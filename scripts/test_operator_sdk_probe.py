"""Portable opt-in SDK hook tests. No runtime qualification credit."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

if __package__:
    from . import operator_sdk_probe as probe
else:
    import operator_sdk_probe as probe


class ProbeTests(unittest.TestCase):
    def test_unsafe_paths_and_mutable_identity_refused(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp).resolve()
            link = root/'link'
            link.symlink_to(root, target_is_directory=True)
            with patch.object(probe, 'capture') as capture:
                for container, image, output in [('b'*64, 'probe:latest', root),
                                                   ('--help', 'sha256:'+'a'*64, root),
                                                   ('b'*64, 'sha256:'+'a'*64, link)]:
                    with self.assertRaises(ValueError):
                        probe.collect(container, image, output)
                capture.assert_not_called()

    def test_bounded_capture_timeout_and_overflow(self):
        with self.assertRaises(subprocess.TimeoutExpired):
            probe.capture([sys.executable, '-c', 'import time;time.sleep(10)'], .05)
        with self.assertRaises(ValueError):
            probe.capture([sys.executable, '-c', 'print("x"*65537)'], 5)

    def test_complete_and_partial_capture(self):
        image = 'sha256:' + 'a' * 64
        for count in (0, 17, 18):
            with tempfile.TemporaryDirectory() as tmp:
                value = {'schema': 'iicp.directory-sdk-probe.v1', 'status': 'PASS',
                         'non_authorizing': True, 'qualification_credit': 0,
                         'matrix': {'rows': [{}] * count}}
                with patch.object(probe, 'capture', side_effect=[image.encode(), b'0', json.dumps(value).encode()]):
                    result = probe.collect('b'*64, image, Path(tmp).resolve())
                self.assertEqual(result['status'], 'PASS' if count == 18 else 'FAIL')
                self.assertTrue((Path(tmp)/'sdk-probe.json').exists())
                self.assertTrue((Path(tmp)/'sdk-capture.json').exists())

    def test_wait_timeout_retains_failure(self):
        image = 'sha256:' + 'a'*64
        with tempfile.TemporaryDirectory() as tmp:
            with patch.object(probe, 'capture', side_effect=[image.encode(), subprocess.TimeoutExpired('wait', 1800)]):
                result = probe.collect('b'*64, image, Path(tmp).resolve())
            self.assertEqual(result['status'], 'FAIL')
            self.assertTrue((Path(tmp)/'sdk-capture.json').exists())

    def test_identity_mismatch_never_waits(self):
        with tempfile.TemporaryDirectory() as tmp:
            with patch.object(probe, 'capture', return_value=b'wrong') as capture:
                result = probe.collect('b'*64, 'sha256:'+'a'*64, Path(tmp).resolve())
            self.assertEqual(capture.call_count, 1)
            self.assertEqual(result['status'], 'FAIL')

    def test_overlay_and_hook_are_test_only(self):
        root = Path(__file__).resolve().parents[1]
        base = (root/'compose.operator.yml').read_text()
        overlay = (root/'compose.operator-sdk-test.yml').read_text()
        script = (root/'scripts/rehearse_operator_upgrade.sh').read_text()
        self.assertIn('APP_ENV: production', base)
        self.assertNotIn('sdk-probe', base)
        self.assertEqual(overlay.count('network_mode: service:app'), 3)
        self.assertIn('ports: !reset []', overlay)
        self.assertNotIn('docker.sock', overlay)
        self.assertIn('pull_policy: never', overlay)
        self.assertLess(script.index('verify_fixture "$NEXT_TAG" forward'), script.index('phase sdk_compatibility'))
        self.assertLess(script.index('phase sdk_compatibility'), script.index('phase result'))
        self.assertIn('rm -sf web scheduler', script)


if __name__ == '__main__':
    unittest.main()
