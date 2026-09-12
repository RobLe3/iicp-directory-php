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
                image_id = 'sha256:' + 'a'*64
                image_ref = 'iicp-pre1-directory-probe:' + 'a'*64
                for container, ref, identity, output in [('b'*64, 'probe:latest', image_id, root),
                                                          ('--help', image_ref, image_id, root),
                                                          ('b'*64, image_ref, image_id, link)]:
                    with self.assertRaises(ValueError):
                        probe.collect(container, ref, identity, output)
                capture.assert_not_called()

    def test_bounded_capture_timeout_and_overflow(self):
        with self.assertRaises(subprocess.TimeoutExpired):
            probe.capture([sys.executable, '-c', 'import time;time.sleep(10)'], .05)
        with self.assertRaises(ValueError):
            probe.capture([sys.executable, '-c', 'print("x"*65537)'], 5)

    def test_complete_and_partial_capture(self):
        image_id = 'sha256:' + 'a' * 64
        image_ref = 'iicp-pre1-directory-probe:' + 'a' * 64
        for count in (0, 17, 18):
            with tempfile.TemporaryDirectory() as tmp:
                value = {'schema': 'iicp.directory-sdk-probe.v1', 'status': 'PASS',
                         'non_authorizing': True, 'qualification_credit': 0,
                         'matrix': {'rows': [{}] * count}}
                with patch.object(probe, 'capture', side_effect=[image_id.encode(), b'0', json.dumps(value).encode()]):
                    result = probe.collect('b'*64, image_ref, image_id, Path(tmp).resolve())
                self.assertEqual(result['status'], 'PASS' if count == 18 else 'FAIL')
                self.assertTrue((Path(tmp)/'sdk-probe.json').exists())
                self.assertTrue((Path(tmp)/'sdk-capture.json').exists())

    def test_wait_timeout_retains_failure(self):
        image_id = 'sha256:' + 'a'*64
        image_ref = 'iicp-pre1-directory-probe:' + 'a'*64
        with tempfile.TemporaryDirectory() as tmp:
            with patch.object(probe, 'capture', side_effect=[image_id.encode(), subprocess.TimeoutExpired('wait', 1800)]):
                result = probe.collect('b'*64, image_ref, image_id, Path(tmp).resolve())
            self.assertEqual(result['status'], 'FAIL')
            self.assertTrue((Path(tmp)/'sdk-capture.json').exists())

    def test_identity_mismatch_never_waits(self):
        with tempfile.TemporaryDirectory() as tmp:
            with patch.object(probe, 'capture', return_value=b'wrong') as capture:
                result = probe.collect('b'*64, 'iicp-pre1-directory-probe:'+'a'*64,
                                       'sha256:'+'a'*64, Path(tmp).resolve())
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
        self.assertEqual(overlay.count('entrypoint: !reset []'), 2)
        self.assertIn('ports: !reset []', overlay)
        self.assertNotIn('docker.sock', overlay)
        self.assertIn('pull_policy: never', overlay)
        self.assertLess(script.index('verify_fixture "$NEXT_TAG" forward'), script.index('phase sdk_compatibility'))
        self.assertLess(script.index('phase sdk_compatibility'), script.index('phase result'))
        self.assertIn('rm -sf web scheduler', script)



if __package__:
    from . import operator_directory_outage as outage
else:
    import operator_directory_outage as outage


class OutageOwnerTests(unittest.TestCase):
    def setUp(self):
        self.paused = False
        self.commands = []
        self.pause_error = False
        self.nonce = 'c'*32
        self.owner_label = 'owned'
        self.network = 'container:'+'b'*64

    def call(self, args, timeout):
        self.commands.append(args)
        if args[0] == 'inspect':
            self.assertIn('{{json (index .Config.Labels', args[2])
            probe = args[-1] == 'a'*64
            return json.dumps({'id': args[-1], 'image': 'sha256:'+'d'*64,
                'owner': self.owner_label, 'running': True,
                'paused': False if probe else self.paused,
                'network': self.network if probe else 'bridge'}).encode()
        if args[0] in ('pause', 'unpause'):
            self.paused = args[0] == 'pause'
            if self.paused and self.pause_error:
                raise subprocess.TimeoutExpired('pause', 10)
            return b''
        if args[0] == 'exec' and args[4] == 'read':
            seq = int(args[5])
            return json.dumps({'schema': outage.SCHEMA, 'nonce': self.nonce,
                'sequence': seq, 'action': {1:'pause', 2:'resume'}[seq]}).encode()
        return b''

    def owner(self):
        return outage.Owner(self.call, 'a'*64, 'b'*64, 'owner', 'owned', 'sha256:'+'d'*64)

    def test_owner_pause_resume_and_bound_receipt(self):
        result = self.owner().run()
        self.assertEqual(result['observed'], ['pause', 'resume'])
        self.assertEqual(result['nonce'], self.nonce)
        self.assertFalse(self.paused)
        self.assertEqual([a[0] for a in self.commands if a[0] in ('pause', 'unpause')], ['pause','unpause'])
        self.assertFalse(any('stop' in a or 'restart' in a for a in self.commands))

    def test_ambiguous_pause_timeout_always_unpauses(self):
        self.pause_error = True
        with self.assertRaises(subprocess.TimeoutExpired):
            self.owner().run()
        self.assertFalse(self.paused)
        self.assertIn(['unpause', 'b'*64], self.commands)

    def test_namespace_and_ownership_rejected_before_pause(self):
        for attribute, value in [('network', 'host'), ('owner_label', 'unrelated')]:
            self.setUp()
            setattr(self, attribute, value)
            with self.assertRaises(ValueError):
                self.owner()
            self.assertFalse(any(a[0] == 'pause' for a in self.commands))

    def test_malformed_request_cannot_control_container(self):
        self.nonce = '--arbitrary-command'
        with self.assertRaises(ValueError):
            self.owner().run()
        self.assertFalse(any(a[0] == 'pause' for a in self.commands))

    def test_ack_failure_after_pause_recovers(self):
        original = self.call
        def call(args, timeout):
            if args[0] == 'exec' and args[4:6] == ['ack', '1']:
                raise ValueError('ack unavailable')
            return original(args, timeout)
        owner = self.owner()
        owner.call = call
        with self.assertRaises(ValueError):
            owner.run()
        self.assertFalse(self.paused)

    def test_unobserved_pause_refuses_ack(self):
        original = self.call
        def call(args, timeout):
            if args[0] == 'pause':
                return b''
            return original(args, timeout)
        owner = self.owner()
        owner.call = call
        with self.assertRaises(ValueError):
            owner.run()
        self.assertFalse(any(a[0] == 'exec' and a[4] == 'ack' for a in self.commands))

if __name__ == '__main__':
    unittest.main()
