#!/usr/bin/env python3
"""Pruebas de scripts/hash-contrasena.py (tarea 51: --subir y --restaurar-config).

  python pruebas/panel/prueba-hash-contrasena.py        (desde la raíz del repositorio)

Sin red: el servidor es un FTP FALSO en memoria y la configuración es ficticia (claves y correos .test).
Escribe solo en una carpeta temporal que borra al terminar. Necesita PHP (DL_PHP o el de herramientas).
Sale con 0 si todo pasa y 1 si algo falla.
"""
import argparse
import contextlib
import getpass
import importlib.util
import io
import json
import re
import shutil
import subprocess
import sys
import tempfile
import types
from datetime import datetime
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[2]
SCRIPT = RAIZ / "scripts" / "hash-contrasena.py"
spec = importlib.util.spec_from_file_location("hash_contrasena", SCRIPT)
hc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(hc)
PHP = hc.buscar_php(None)

fallos = 0
total = 0


def ok(cond, texto, detalle=""):
    global fallos, total
    total += 1
    if not cond:
        fallos += 1
    print(("  ok   " if cond else "  FALLA ") + texto + (f" · {detalle}" if detalle and not cond else ""))


VIEJO = "$2y$10$abcdefghijklmnopqrstuuJ0nYp6yJ3Zl0b8Jm4QeVqYbW6L2u8xy"
CONFIG = """<?php
// Configuración FICTICIA de pruebas (no es la real)
return [
    'admin_email' => 'admin@ejemplo.test',
    'admin_password_hash' => '""" + VIEJO + """',
    'anthropic_api_key' => 'sk-ficticia-de-prueba-0000',
    'panel_zona' => 'America/Argentina/Buenos_Aires',
    'origenes' => ['https://dl-dev.com', 'https://www.dl-dev.com'],
    'limite' => 5
];
"""
VALORES = {"admin_password_hash": "$argon2id$v=19$m=65536,t=4,p=1$c2FsZWplbXBsbzEyMzQ$aGFzaGZpY3RpY2lvZGVwcnVlYmFzMTIzNDU2Nzg",
           "panel_sal": "SalFicticiaDePruebas_0123456789-abcdefghijABCDEFGHIJ0123456789xyz"}


class FtpFalso:
    """Lo justo de la clase Ftp de publicar-ftp.py, en memoria."""

    def __init__(self, archivos, modos, chmod_roto=False):
        self.archivos, self.modos, self.chmod_roto = archivos, modos, chmod_roto
        self.secretos, self.ops, self.cerrado = [], [], False
        self.f = self

    def sendcmd(self, cmd):
        m = re.fullmatch(r"SITE CHMOD (\d{3}) (.+)", cmd)
        self.ops.append(("CHMOD", m[1], m[2]))
        self.modos[m[2]] = "0644" if self.chmod_roto else "0" + m[1]

    def info(self, r):
        return (len(self.archivos[r]), "20261028000000") if r in self.archivos else None

    def modo(self, r):
        return self.modos.get(r, "?")

    def leer(self, r):
        self.ops.append(("RETR", r))
        return self.archivos[r]

    def subir(self, d, r):
        self.ops.append(("STOR", r))
        self.archivos[r] = d
        self.modos[r] = "0644"

    def renombrar(self, a, b):
        self.ops.append(("RNFR", a, b))
        self.archivos[b] = self.archivos.pop(a)
        self.modos[b] = self.modos.pop(a)

    def borrar(self, r):
        self.ops.append(("DELE", r))
        self.archivos.pop(r, None)
        self.modos.pop(r, None)

    def limpio(self, e):
        t = str(e)
        for s in self.secretos:
            t = t.replace(s, "***")
        return t

    def cerrar(self):
        self.cerrado = True


def args(**kw):
    base = dict(aplicar=False, sin_red=False, php=PHP, bcrypt=False, aprobado_por="Persona de prueba", restaurar_config=None)
    base.update(kw)
    return argparse.Namespace(**base)


def ejecutar(fn, *a, **kw):
    """(código o mensaje de salida, texto impreso)"""
    salida = io.StringIO()
    with contextlib.redirect_stdout(salida):
        try:
            r = fn(*a, **kw)
        except SystemExit as e:
            r = e.code
    return r, salida.getvalue()


TMP = Path(tempfile.mkdtemp(prefix="dl-hash-prueba-"))
pf = types.SimpleNamespace(BACKUPS=TMP / "backups", PROYECTO=TMP)
AHORA = datetime(2026, 10, 28, 12, 0, 0)
REM = hc.CONFIG_REMOTA

try:
    print("1. Qué claves cambiarían")
    ok(hc.analizar(CONFIG) == {"admin_password_hash": "sustituir", "panel_sal": "añadir"}, "hash existente → sustituir; sal ausente → añadir")
    for malo, nombre in ((CONFIG.replace("'limite' => 5", "'limite' => 5,\n    'admin_password_hash' => 'x'"), "clave repetida"),
                         (CONFIG.replace("'" + VIEJO + "'", "getenv('HASH')"), "valor que no es un texto simple"),
                         (CONFIG.replace("];", "]"), "sin «];» final y hay que añadir")):
        try:
            hc.analizar(malo)
            ok(False, f"rechaza: {nombre}")
        except ValueError:
            ok(True, f"rechaza: {nombre}")

    print("2. Sustituir solo dos claves (comprobado con PHP)")
    nuevo = hc.sustituir(CONFIG, VALORES)
    ok(f"'admin_password_hash' => '{VALORES['admin_password_hash']}'," in nuevo and VIEJO not in nuevo, "el hash se sustituye en su línea")
    ok(f"    'panel_sal' => '{VALORES['panel_sal']}',\n];" in nuevo and "'limite' => 5,\n" in nuevo, "la sal se añade al final y se pone la coma que faltaba")
    try:
        hc.comprobar(PHP, CONFIG, nuevo, VALORES)
        ok(True, "PHP lo acepta y el resto de claves queda idéntico")
    except ValueError as e:
        ok(False, "PHP lo acepta y el resto de claves queda idéntico", str(e))
    ok(nuevo.replace(VALORES["admin_password_hash"], VIEJO).replace(f"    'panel_sal' => '{VALORES['panel_sal']}',\n", "").replace("'limite' => 5,", "'limite' => 5") == CONFIG,
       "nada más cambia en el texto")
    crlf = CONFIG.replace("\n", "\r\n").replace("'limite' => 5\r\n", "'limite' => 5,\r\n    'panel_sal' => 'vieja-sal-ficticia-de-32-caracteres-xx',\r\n")
    n2 = hc.sustituir(crlf, VALORES)
    ok("\n" not in n2.replace("\r\n", "") and n2.count("'panel_sal'") == 1 and hc.analizar(crlf) == {"admin_password_hash": "sustituir", "panel_sal": "sustituir"},
       "con las dos claves presentes sustituye ambas y respeta los finales de línea CRLF")
    for trampa, nombre in ((nuevo.replace("sk-ficticia-de-prueba-0000", "sk-otra"), "otra clave cambiada"),
                           (nuevo.replace("];", "];;("), "PHP inválido"),
                           (nuevo.replace("    'limite' => 5,", "    'limite' => 5, 'extra' => 1,"), "clave nueva no pedida")):
        try:
            hc.comprobar(PHP, CONFIG, trampa, VALORES)
            ok(False, f"la comprobación detecta: {nombre}")
        except ValueError:
            ok(True, f"la comprobación detecta: {nombre}")
    try:
        hc.sustituir(CONFIG, {"admin_password_hash": "x' . system('id') . '", "panel_sal": VALORES["panel_sal"]})
        ok(False, "rechaza valores con comillas u otros caracteres")
    except ValueError:
        ok(True, "rechaza valores con comillas u otros caracteres")

    print("3. --subir en simulación (por defecto)")
    ftp = FtpFalso({REM: CONFIG.encode()}, {REM: "0600"})
    pedidas = []
    r, out = ejecutar(hc.cmd_subir, args(), pf, ftp_factory=lambda: ftp, pedir=lambda: pedidas.append(1) or VALORES, ahora=AHORA)
    ok(r == 0 and "SIMULAR" in out and "admin_password_hash: existe -> se sustituiría" in out and "panel_sal: no existe -> se añadiría" in out,
       "dice qué claves cambiarían", out)
    ok("permisos 0600" in out and "SITE CHMOD 600" in out, "muestra los permisos que se conservarían")
    ok(VIEJO not in out and "sk-ficticia" not in out and "admin@ejemplo.test" not in out, "no muestra ningún valor de la configuración")
    ok(pedidas == [], "no pide la contraseña")
    ok(ftp.archivos == {REM: CONFIG.encode()} and [o[0] for o in ftp.ops] == ["RETR"] and not pf.BACKUPS.exists() and ftp.cerrado,
       "no sube, no renombra, no guarda copia y cierra la conexión", str(ftp.ops))

    print("4. --subir --aplicar (servidor falso)")
    ftp = FtpFalso({REM: CONFIG.encode(), "/public_html/index.html": b"web"}, {REM: "0600"})
    r, out = ejecutar(hc.cmd_subir, args(aplicar=True), pf, ftp_factory=lambda: ftp, pedir=lambda: dict(VALORES), ahora=AHORA)
    final = ftp.archivos[REM].decode()
    ok(r == 0 and final == hc.sustituir(CONFIG, VALORES), "el servidor queda con las dos claves nuevas y lo demás igual", out)
    ok(ftp.modos[REM] == "0600", "conserva los permisos (0600)", ftp.modos[REM])
    sello = AHORA.strftime("%Y-%m-%d-%H%M%S")
    ops = [o[0] for o in ftp.ops]
    ok(("STOR", f"{REM}.tmp-{sello}") in ftp.ops and ops.index("STOR") < ops.index("CHMOD") < ops.index("RNFR") and not any(k.startswith(REM + ".tmp") for k in ftp.archivos),
       "sube a un temporal, pone permisos y renombra; no queda el temporal", str(ftp.ops))
    ok(ftp.archivos["/public_html/index.html"] == b"web", "no toca nada más del servidor")
    copia = pf.BACKUPS / sello / "dl-auditoria-config.php"
    ok(copia.is_file() and copia.read_bytes() == CONFIG.encode(), "guarda copia del anterior en docs/backups/<fecha-hora>/")
    mf = (pf.BACKUPS / sello / "manifiesto.json").read_text(encoding="utf-8")
    ok(all(s not in mf for s in (VALORES["admin_password_hash"], VALORES["panel_sal"], VIEJO, "sk-ficticia")) and json.loads(mf)["aprobado_por"] == "Persona de prueba",
       "el manifiesto no lleva secretos y anota quién lo aprobó")
    ok(all(v not in out for v in VALORES.values()) and VIEJO not in out, "la salida no muestra el hash ni la sal")
    ok("--restaurar-config" in out, "dice cómo volver atrás")

    print("5. --subir --aplicar: fallos que dejan el servidor como estaba")
    ftp = FtpFalso({REM: CONFIG.encode()}, {REM: "0600"}, chmod_roto=True)
    r, out = ejecutar(hc.cmd_subir, args(aplicar=True), pf, ftp_factory=lambda: ftp, pedir=lambda: dict(VALORES), ahora=datetime(2026, 10, 28, 12, 0, 1))
    ok(isinstance(r, str) and ftp.archivos == {REM: CONFIG.encode()} and ("DELE", f"{REM}.tmp-2026-10-28-120001") in ftp.ops,
       "si el servidor no pone los permisos, borra el temporal y no renombra", str(r))
    pedidas = []
    ftp = FtpFalso({REM: CONFIG.encode()}, {})
    r, out = ejecutar(hc.cmd_subir, args(aplicar=True), pf, ftp_factory=lambda: ftp, pedir=lambda: pedidas.append(1) or dict(VALORES), ahora=AHORA)
    ok(isinstance(r, str) and "permisos" in r and pedidas == [] and ftp.archivos == {REM: CONFIG.encode()}, "sin permisos conocidos no aplica (ni pide la contraseña)", str(r))
    ftp = FtpFalso({}, {})
    r, out = ejecutar(hc.cmd_subir, args(aplicar=True), pf, ftp_factory=lambda: ftp, pedir=lambda: dict(VALORES), ahora=AHORA)
    ok(isinstance(r, str) and "no existe" in r and ftp.archivos == {}, "si el archivo no existe no lo crea")

    print("6. --restaurar-config")
    ftp = FtpFalso({REM: final.encode()}, {REM: "0600"})
    r, out = ejecutar(hc.cmd_restaurar, args(restaurar_config=str(pf.BACKUPS / sello)), pf, ftp_factory=lambda: ftp, ahora=datetime(2026, 10, 28, 13, 0, 0))
    ok(r == 0 and ftp.archivos[REM] == final.encode() and "SIMULAR" in out, "simula sin cambiar nada")
    r, out = ejecutar(hc.cmd_restaurar, args(restaurar_config=str(pf.BACKUPS / sello), aplicar=True), pf, ftp_factory=lambda: ftp, ahora=datetime(2026, 10, 28, 13, 0, 0))
    ok(r == 0 and ftp.archivos[REM] == CONFIG.encode() and ftp.modos[REM] == "0600", "repone la copia con los mismos permisos", out)
    ok((pf.BACKUPS / "2026-10-28-130000" / "dl-auditoria-config.php").read_bytes() == final.encode(), "antes guarda copia de lo que había (se puede deshacer)")

    print("7. La contraseña: getpass y nunca en pantalla")
    clave = "clave-ficticia-de-prueba-Ñ-2026"
    original_getpass = getpass.getpass
    getpass.getpass = lambda prompt="": clave
    try:
        r, out = ejecutar(hc.generar_valores, PHP, False)
    finally:
        getpass.getpass = original_getpass
    ok(isinstance(r, dict) and r["admin_password_hash"].startswith("$argon2id$") and len(r["panel_sal"]) == 64, "genera hash argon2id y sal de 64 caracteres")
    ok(clave not in out and r["admin_password_hash"] not in out, "no imprime la contraseña ni el hash al generarlos")
    verif = subprocess.run([PHP, "-r", "$d = json_decode(stream_get_contents(STDIN), true); echo password_verify($d[0], $d[1]) ? 'si' : 'no';"],
                           input=json.dumps([clave, r["admin_password_hash"]]).encode(), capture_output=True)
    ok(verif.stdout == b"si", "el hash corresponde a la contraseña tecleada")
    src = SCRIPT.read_text(encoding="utf-8")
    ok(src.count("getpass.getpass(") == 2 and "input(" not in src and "argv" not in src.split("CODIGO_PHP")[0], "la contraseña solo se pide con getpass (nunca input() ni argumentos)")

    print("8. Línea de comandos")
    def cli(*a):
        return subprocess.run([sys.executable, str(SCRIPT), *a], capture_output=True, text=True, encoding="utf-8", timeout=60)
    c = cli("--subir", "--sin-red")
    ok(c.returncode == 0 and "SIMULAR" in c.stdout and "Simulación terminada" in c.stdout, "--subir --sin-red simula sin conectar", c.stdout + c.stderr)
    c = cli("--subir", "--aplicar")
    ok(c.returncode != 0 and "--aprobado-por" in c.stderr, "--aplicar sin --aprobado-por se niega")
    c = cli("--aplicar", "--aprobado-por", "X")
    ok(c.returncode != 0 and "solo valen con --subir" in c.stderr, "--aplicar sin --subir se niega")
    c = cli("--subir", "--sin-red", "--aplicar", "--aprobado-por", "X")
    ok(c.returncode != 0 and "--sin-red solo vale en simulación" in c.stderr, "--sin-red no vale al aplicar")
finally:
    shutil.rmtree(TMP, ignore_errors=True)

print(f"\n{total - fallos}/{total} comprobaciones correctas" if not fallos else f"\nFALLAN {fallos} de {total}")
sys.exit(1 if fallos else 0)
