#!/usr/bin/env python3
"""Capture an already-owned SDK probe before the Compose owner tears it down."""
import argparse
import hashlib
import json
import re
import subprocess
import threading

if __package__:
    from .operator_directory_outage import Owner
    from .operator_rehearsal_evidence import safe_directory, write_json
else:
    from operator_directory_outage import Owner
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


def validate_identity(container, image_ref, image_id):
    expected_ref = 'iicp-pre1-directory-probe:' + image_id.removeprefix('sha256:')
    if (not re.fullmatch(r'[0-9a-f]{64}', container)
            or not re.fullmatch(r'sha256:[0-9a-f]{64}', image_id)
            or image_ref != expected_ref):
        raise ValueError('exact_container_and_image_required')


def collect(container, image_ref, image_id, output, *, app=None, project=None):
    validate_identity(container, image_ref, image_id)
    output = safe_directory(output)
    result = {'schema': 'iicp.directory-sdk-capture.v1', 'status': 'FAIL',
              'image_ref': image_ref, 'image_id': image_id,
              'qualification_credit': 0, 'non_authorizing': True}
    try:
        identity = capture(['docker', 'inspect', '--format', '{{.Image}}', container], 30).decode().strip()
        if identity != image_id:
            raise ValueError('probe_image_identity_differs')
        owner = None
        if app is not None:
            controller = Owner(lambda args, timeout: capture(['docker', *args], timeout),
                               container, app, 'com.docker.compose.project', project, image_id)
            try:
                owner = controller.run()
            finally:
                write_json(output / 'directory-outage-control.json', controller.snapshot())
        status = capture(['docker', 'wait', container], 1800).strip()
        raw = capture(['docker', 'logs', '--tail', '1000', container], 30)
        value = json.loads(raw)
        write_json(output / 'sdk-probe.json', value)
        if (status != b'0' or value.get('schema') != 'iicp.directory-sdk-probe.v1'
                or value.get('status') != 'PASS' or value.get('non_authorizing') is not True
                or type(value.get('qualification_credit')) is not int or value['qualification_credit'] != 0
                or len(value.get('matrix', {}).get('rows', [])) != 18):
            raise ValueError('probe_failed_or_incomplete')
        if owner is not None:
            if value.get('outage_nonce') != owner['nonce']:
                raise ValueError('outage_nonce_differs')
            owner['probe_sha256'] = hashlib.sha256(raw).hexdigest()
            write_json(output / 'directory-outage.json', owner)
        result['status'] = 'PASS'
    except (OSError, ValueError, RuntimeError, TypeError, AttributeError, subprocess.SubprocessError) as error:
        result['failure_class'] = type(error).__name__
    finally:
        write_json(output / 'sdk-capture.json', result)
    return result


if __name__ == '__main__':
    from pathlib import Path
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--container', required=True)
    parser.add_argument('--image-ref', required=True)
    parser.add_argument('--image-id', required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument("--app")
    parser.add_argument("--project")
    args = parser.parse_args()
    if bool(args.app) != bool(args.project):
        parser.error("app and project required together")
    raise SystemExit(0 if collect(args.container, args.image_ref, args.image_id, args.output, app=args.app, project=args.project)['status'] == 'PASS' else 1)
