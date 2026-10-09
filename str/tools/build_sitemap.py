#!/usr/bin/env python3
"""Build the www Tickex sitemap from live self-canonical public pages.

No application bootstrap, schema migrations, sessions, or database writes.
Run after public-page deployments; inspect the report before publishing output.
"""
import argparse
import json
import re
import sqlite3
import urllib.error
import urllib.parse
import urllib.request
import urllib.robotparser
import xml.etree.ElementTree as ET
from html.parser import HTMLParser
from pathlib import Path

BASE = 'https://www.tickex.com.ar'
PUBLIC_PATHS = ('/', '/contacto.php', '/demo/', '/ayuda/', '/manual/',
                '/legal.php?doc=terms', '/legal.php?doc=privacy', '/arrepentimiento.php')
LIMIT_URLS = 50000
LIMIT_BYTES = 50 * 1024 * 1024

class Metadata(HTMLParser):
    def __init__(self):
        HTMLParser.__init__(self)
        self.canonicals, self.robots = [], []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'link' and 'canonical' in attrs.get('rel', '').lower().split():
            self.canonicals.append(attrs.get('href', ''))
        if tag == 'meta' and attrs.get('name', '').lower() in ('robots', 'googlebot'):
            self.robots.append(attrs.get('content', '').lower())

def request(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'Tickex-Sitemap-Validator/1.0'})
    try:
        response = urllib.request.urlopen(req, timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        data = response.read(2 * 1024 * 1024 + 1)
        if len(data) > 2 * 1024 * 1024:
            raise ValueError('Page exceeds validation size limit')
        return response.getcode(), response.geturl(), response.headers, data

def public_candidates(docroot):
    candidates = [BASE + path for path in PUBLIC_PATHS]
    dbfile = docroot / 'save_the_rave.sqlite'
    if not dbfile.is_file():
        raise ValueError('Production database missing; refusing incomplete inventory')
    # SQLite opens read-only; query_only additionally rejects accidental writes.
    conn = sqlite3.connect(dbfile.resolve().as_uri() + '?mode=ro', uri=True)
    try:
        conn.execute('PRAGMA query_only=ON')
        sites = conn.execute('SELECT admin_id,slug_publico FROM clientes_sites WHERE visible=1').fetchall()
        for admin, slug in sites:
            if not re.fullmatch('[a-z0-9]+(?:-[a-z0-9]+)*', slug or ''):
                continue
            page = BASE + '/site.php?slug=' + urllib.parse.quote(slug, safe='')
            candidates.append(page)
            rows = conn.execute('''SELECT slug FROM eventos WHERE publicado_site=1
                AND creado_por_admin_id=? AND (borrado_en IS NULL OR borrado_en='')''', (admin,)).fetchall()
            for (event,) in rows:
                if re.fullmatch('[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*', event or ''):
                    candidates.append(page + '&event=' + urllib.parse.quote(event, safe=''))
    finally:
        conn.close()
    return sorted(set(candidates))

def eligible(url, status, final, headers, body, robots):
    if status != 200:
        return 'http_' + str(status)
    if final != url:
        return 'redirect_or_alias'
    if 'text/html' not in headers.get('Content-Type', '').lower():
        return 'not_html'
    meta = Metadata()
    meta.feed(body.decode('utf-8', 'replace'))
    directives = ','.join(meta.robots + [headers.get('X-Robots-Tag', '').lower()])
    if re.search(r'\b(noindex|none)\b', directives):
        return 'noindex'
    if not robots.can_fetch('Googlebot', url) or not robots.can_fetch('*', url):
        return 'robots_disallowed'
    if len(meta.canonicals) != 1:
        return 'missing_or_multiple_canonical'
    canonical = urllib.parse.urljoin(url, meta.canonicals[0])
    if canonical != url:
        return 'different_canonical'
    parsed = urllib.parse.urlsplit(canonical)
    if parsed.scheme != 'https' or parsed.netloc != 'www.tickex.com.ar' or parsed.fragment:
        return 'outside_canonical_scope'
    return None

def render(urls):
    if not urls or len(urls) > LIMIT_URLS:
        raise ValueError('URL count invalid; use sitemap index before exceeding 50000')
    root = ET.Element('urlset', xmlns='http://www.sitemaps.org/schemas/sitemap/0.9')
    for url in sorted(set(urls)):
        ET.SubElement(ET.SubElement(root, 'url'), 'loc').text = url
    # Deliberately no lastmod: deployment time is not content-modification evidence.
    data = b'<?xml version="1.0" encoding="UTF-8"?>\n' + ET.tostring(root, encoding='utf-8') + b'\n'
    if len(data) > LIMIT_BYTES:
        raise ValueError('XML too large; use sitemap index before exceeding 50MiB')
    ET.fromstring(data)
    return data

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--docroot', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--report', required=True, type=Path)
    args = parser.parse_args()
    status, final, headers, body = request(BASE + '/robots.txt')
    if status != 200 or final != BASE + '/robots.txt':
        raise ValueError('Cannot validate robots.txt')
    robots = urllib.robotparser.RobotFileParser()
    robots.parse(body.decode('utf-8').splitlines())
    included, excluded = [], []
    for url in public_candidates(args.docroot):
        status, final, headers, body = request(url)
        reason = eligible(url, status, final, headers, body, robots)
        if reason:
            excluded.append({'url': url, 'reason': reason})
        else:
            included.append(url)
    if BASE + '/' not in included:
        raise ValueError('Canonical home not eligible; refusing to replace sitemap')
    data = render(included)
    report = {'host': BASE, 'included': included, 'excluded': excluded,
              'urls': len(included), 'bytes': len(data), 'lastmod': 'omitted_no_verified_change_dates'}
    args.output.write_bytes(data)
    args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'urls': len(included), 'bytes': len(data), 'excluded': len(excluded)}))

if __name__ == '__main__':
    main()
