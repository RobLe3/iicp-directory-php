#!/usr/bin/env python3
"""Capture an already-owned SDK probe before the Compose owner tears it down."""
import argparse
import json
import re
import subprocess
import threading

if __package__:
    from .operator_rehearsal_evidence import safe_directory, write_json
else:
    from operator_rehearsal_evidence import safe_directory, write_json


def capture(args, timeout):
    process = subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    output = bytearray()
    overflow = threading.Event()

    def drain():
        with process.stdout:
            while block := process.stdout.read(4096):
                room = 65536 - len(output)
                output.extend(block[:room])
                if len(block) > room:
                    overflow.set()

    reader = threading.Thread(target=drain, daemon=True)
    reader.start()
    try:
        process.wait(timeout=timeout)
    finally:
        if process.poll() is None:
            process.kill()
            process.wait(timeout=5)
        reader.join(timeout=5)
    if reader.is_alive() or overflow.is_set() or process.returncode:
        raise ValueError('bounded_probe_capture_failed')
    return bytes(output)


def collect(container, image, output):
    if not re.fullmatch(r'[0-9a-f]{64}', container) or not re.fullmatch(r'sha256:[0-9a-f]{64}', image):
        raise ValueError('exact_container_and_image_required')
    output = safe_directory(output)
    result = {'schema': 'iicp.directory-sdk-capture.v1', 'status': 'FAIL',
              'image': image, 'qualification_credit': 0, 'non_authorizing': True}
    try:
        identity = capture(['docker', 'inspect', '--format', '{{.Image}}', container], 30).decode().strip()
        if identity != image:
            raise ValueError('probe_image_identity_differs')
        status = capture(['docker', 'wait', container], 1800).strip()
        raw = capture(['docker', 'logs', '--tail', '1000', container], 30)
        value = json.loads(raw)
        write_json(output / 'sdk-probe.json', value)
        if (status != b'0' or value.get('schema') != 'iicp.directory-sdk-probe.v1'
                or value.get('status') != 'PASS' or value.get('non_authorizing') is not True
                or type(value.get('qualification_credit')) is not int or value['qualification_credit'] != 0
                or len(value.get('matrix', {}).get('rows', [])) != 18):
            raise ValueError('probe_failed_or_incomplete')
        result['status'] = 'PASS'
    except (OSError, ValueError, TypeError, AttributeError, subprocess.SubprocessError) as error:
        result['failure_class'] = type(error).__name__
    finally:
        write_json(output / 'sdk-capture.json', result)
    return result


if __name__ == '__main__':
    from pathlib import Path
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--container', required=True)
    parser.add_argument('--image', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    raise SystemExit(0 if collect(args.container, args.image, args.output)['status'] == 'PASS' else 1)
