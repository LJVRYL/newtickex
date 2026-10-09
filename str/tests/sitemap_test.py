import importlib.util
import unittest
import urllib.robotparser
import xml.etree.ElementTree as ET
from pathlib import Path

spec = importlib.util.spec_from_file_location('sitemap', str(Path(__file__).resolve().parents[1] / 'tools/build_sitemap.py'))
s = importlib.util.module_from_spec(spec)
spec.loader.exec_module(s)

class SitemapTest(unittest.TestCase):
    def setUp(self):
        self.robots = urllib.robotparser.RobotFileParser()
        self.robots.parse(['User-agent: *', 'Disallow: /mi-cuenta'])
        self.url = s.BASE + '/demo/'
        self.headers = {'Content-Type': 'text/html; charset=UTF-8'}
        self.body = ('<link rel="canonical" href="' + self.url + '">').encode()

    def reason(self, **kw):
        args = dict(url=self.url, status=200, final=self.url, headers=self.headers, body=self.body, robots=self.robots)
        args.update(kw)
        return s.eligible(**args)

    def test_public_canonical(self):
        self.assertIsNone(self.reason())

    def test_redirect_and_error_excluded(self):
        self.assertEqual('redirect_or_alias', self.reason(final=s.BASE + '/'))
        self.assertEqual('http_404', self.reason(status=404))

    def test_noindex_meta_and_header(self):
        self.assertEqual('noindex', self.reason(body=self.body + b'<meta name="robots" content="noindex,follow">'))
        self.assertEqual('noindex', self.reason(headers={'Content-Type':'text/html', 'X-Robots-Tag':'noindex'}))

    def test_private_robots_excluded(self):
        self.assertEqual('robots_disallowed', self.reason(url=s.BASE+'/mi-cuenta', final=s.BASE+'/mi-cuenta'))

    def test_missing_and_other_canonical(self):
        self.assertEqual('missing_or_multiple_canonical', self.reason(body=b'<title>Not enough evidence</title>'))
        self.assertEqual('different_canonical', self.reason(body=b'<link rel="canonical" href="https://other.example/">'))

    def test_xml_escaping_and_no_invented_lastmod(self):
        url=s.BASE+'/site.php?slug=public&event=published'
        data=s.render([url])
        self.assertIn(b'&amp;',data)
        root=ET.fromstring(data)
        self.assertEqual(url,root[0][0].text)
        self.assertNotIn(b'lastmod',data)

    def test_limits_and_empty(self):
        with self.assertRaises(ValueError):s.render([])
        with self.assertRaises(ValueError):s.render(['x']*50001)

if __name__ == '__main__':unittest.main()
