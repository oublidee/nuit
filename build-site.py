"""Génère la page GitHub Pages à partir du service PHP autonome."""
from pathlib import Path
import base64
import hashlib
import re

root = Path(__file__).resolve().parent
source = (root / 'service.php').read_text()
html = '<!DOCTYPE html>' + source.split('<!DOCTYPE html>', 1)[1]
html = html.replace(' nonce="<?= $nonce ?>"', '')
html = html.replace('<?= json_encode($token) ?>', "''")
html = html.replace('const HOSTED=false;', 'const HOSTED=true;')
assert '<?' not in html, 'Un fragment PHP reste dans le site statique'
def digest(block):
    return "'sha256-" + base64.b64encode(hashlib.sha256(block.encode()).digest()).decode() + "'"
script = re.search(r'<script>(.*?)</script>', html, re.S).group(1)
style = re.search(r'<style>(.*?)</style>', html, re.S).group(1)
policy = "default-src 'none'; script-src " + digest(script) + "; style-src " + digest(style) + "; connect-src 'self' http://127.0.0.1:8787; img-src 'self' data:; base-uri 'none'; form-action 'self'"
html = html.replace('<meta charset="utf-8">', '<meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' + policy + '">', 1)
(root / 'index.html').write_text(html)
print('index.html généré avec des empreintes CSP pour le script et les styles.')
