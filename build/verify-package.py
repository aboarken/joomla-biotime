#!/usr/bin/env python3
"""Check that a built ZIP is an installable Joomla component package.

Usage: build/verify-package.py <package.zip> [expected-version]

Checks: no development files (tests, vendor, docs, build), manifest at the root, type="component" method="upgrade", every file and folder the
manifest names exists, SQL install/update files exist (including one for this version), the
installer script and service provider exist, and the version matches when one is given.
"""
import json
import os
import sys
import tempfile
import xml.etree.ElementTree as ET
import zipfile


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 2

    package, expected = sys.argv[1], (sys.argv[2] if len(sys.argv) > 2 else None)
    errors = []

    with tempfile.TemporaryDirectory() as d:
        with zipfile.ZipFile(package) as z:
            for name in z.namelist():
                if name.startswith('/') or '..' in name.split('/'):
                    print(f'Unsafe path in package: {name}')
                    return 1
                if name.split('/')[0] in ('tests', 'vendor', 'docs', 'build', '.github') or name in ('composer.json', 'composer.lock', 'phpunit.xml.dist'):
                    errors.append(f'development file in package: {name}')
            z.extractall(d)

        manifest_path = os.path.join(d, 'biotime.xml')
        if not os.path.isfile(manifest_path):
            print('biotime.xml is not at the root of the package')
            return 1

        m = ET.parse(manifest_path).getroot()
        if m.tag != 'extension' or m.get('type') != 'component' or m.get('method') != 'upgrade':
            errors.append('manifest must be <extension type="component" method="upgrade">')

        version = (m.findtext('version') or '').strip()
        if expected and version != expected:
            errors.append(f'manifest version {version} does not match {expected}')

        def check(node, base):
            if node is None:
                errors.append('missing <files>, <media> or <administration><files> section')
                return
            for f in node.findall('filename'):
                if not os.path.isfile(os.path.join(d, base, f.text)):
                    errors.append(f'missing file {base}/{f.text}')
            for f in node.findall('folder'):
                if not os.path.isdir(os.path.join(d, base, f.text)):
                    errors.append(f'missing folder {base}/{f.text}')

        site = m.find('files')
        check(site, site.get('folder', '') if site is not None else '')
        media = m.find('media')
        check(media, media.get('folder', '') if media is not None else '')
        admin = m.find('administration/files')
        check(admin, admin.get('folder', '') if admin is not None else '')

        for f in m.iter('file'):
            if not os.path.isfile(os.path.join(d, 'administrator', f.text)):
                errors.append(f'missing SQL file administrator/{f.text}')

        for s in m.iter('schemapath'):
            path = os.path.join(d, 'administrator', s.text)
            if not os.path.isfile(os.path.join(path, version + '.sql')):
                errors.append(f'missing update file administrator/{s.text}/{version}.sql')

        script = m.findtext('scriptfile')
        if script and not os.path.isfile(os.path.join(d, script)):
            errors.append(f'missing installer script {script}')

        # 1.2.0: the bundled PDF library (Export PDF), with its loader and its one font family.
        for f in ('administrator/vendor/autoload.php', 'administrator/vendor/mpdf/mpdf/src/Mpdf.php',
                  'administrator/vendor/mpdf/mpdf/ttfonts/DejaVuSansCondensed.ttf', 'administrator/vendor/setasign/fpdi/src/FpdiTrait.php'):
            if not os.path.isfile(os.path.join(d, f)):
                errors.append(f'missing PDF library file {f}')

        # 1.3.3: every asset carries the component version, so its address (file.js?1.3.3) changes
        # with each release. Joomla's extension update does not renew its media version, and
        # browsers would keep the previous release's scripts and styles.
        assets_path = os.path.join(d, 'media', 'joomla.asset.json')
        try:
            with open(assets_path, encoding='utf-8') as fh:
                assets = json.load(fh)
            if assets.get('version') != version:
                errors.append(f'media/joomla.asset.json version {assets.get("version")} does not match {version}')
            for a in assets.get('assets', []):
                if a.get('version') != version:
                    errors.append(f'asset {a.get("name")} ({a.get("type")}) has version {a.get("version")!r}, expected {version}')
        except (OSError, ValueError) as e:
            errors.append(f'media/joomla.asset.json unreadable: {e}')

        if not os.path.isfile(os.path.join(d, 'administrator/services/provider.php')):
            errors.append('missing administrator/services/provider.php')

    if errors:
        print('Package check FAILED:')
        for e in errors:
            print(' -', e)
        return 1

    print(f'Package OK: com_biotime {version}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
