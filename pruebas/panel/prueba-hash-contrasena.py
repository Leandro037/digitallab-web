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
    base = dict(aplicar=False, sin_red=False, php=PHP, bcrypt=False, aprobado_por="Persona de prueba", restaurar_config=None,
                solo_sal=False, forzar=False)
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
    copia = pf.BACKUPS / sello / "claves-previas.json"
    guardado = copia.read_text(encoding="utf-8") if copia.is_file() else ""
    ok(copia.is_file() and json.loads(guardado)["claves"] == {"admin_password_hash": "'" + VIEJO + "'", "panel_sal": None},
       "guarda en docs/backups/<fecha-hora>/ solo el hash anterior (y que la sal no existía)")
    ok(sorted(p.name for p in (pf.BACKUPS / sello).iterdir()) == ["claves-previas.json", "manifiesto.json"]
       and all(s not in guardado for s in ("sk-ficticia", "admin@ejemplo.test", "America/Argentina", "dl-dev.com", VALORES["admin_password_hash"], VALORES["panel_sal"])),
       "no copia el config completo: ninguna otra clave ni valor nuevo, y ningún dl-auditoria-config.php")
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
    SIN_SAL = CONFIG.replace("'limite' => 5\n", "'limite' => 5,\n")   # lo que queda al quitar la sal que añadió --subir
    ftp = FtpFalso({REM: final.encode()}, {REM: "0600"})
    r, out = ejecutar(hc.cmd_restaurar, args(restaurar_config=str(pf.BACKUPS / sello)), pf, ftp_factory=lambda: ftp, ahora=datetime(2026, 10, 28, 13, 0, 0))
    ok(r == 0 and ftp.archivos[REM] == final.encode() and "SIMULAR" in out and "panel_sal: no existía antes -> se quitaría" in out
       and "admin_password_hash: se sustituiría por su valor anterior" in out, "simula sin cambiar nada y dice qué haría", out)
    ok(VIEJO not in out and VALORES["panel_sal"] not in out and not (pf.BACKUPS / "2026-10-28-130000").exists(), "la simulación no muestra valores ni guarda nada")
    r, out = ejecutar(hc.cmd_restaurar, args(restaurar_config=str(pf.BACKUPS / sello), aplicar=True), pf, ftp_factory=lambda: ftp, ahora=datetime(2026, 10, 28, 13, 0, 0))
    ok(r == 0 and ftp.archivos[REM] == SIN_SAL.encode() and ftp.modos[REM] == "0600", "repone el hash anterior, quita la sal que no existía y conserva los permisos", out)
    try:
        hc.comprobar_restauracion(PHP, CONFIG, ftp.archivos[REM].decode(), {"admin_password_hash": "'" + VIEJO + "'", "panel_sal": None})
        ok(True, "PHP confirma: mismas claves y valores que el config original")
    except ValueError as e:
        ok(False, "PHP confirma: mismas claves y valores que el config original", str(e))
    for trampa, nombre in ((ftp.archivos[REM].decode().replace("sk-ficticia-de-prueba-0000", "sk-otra"), "otra clave cambiada"),
                           (ftp.archivos[REM].decode().replace("'" + VIEJO + "'", "'$2y$10$otro-hash-distinto-0000000000000'"), "hash distinto al anterior")):
        try:
            hc.comprobar_restauracion(PHP, CONFIG, trampa, {"admin_password_hash": "'" + VIEJO + "'", "panel_sal": None})
            ok(False, f"la comprobación de la restauración detecta: {nombre}")
        except ValueError:
            ok(True, f"la comprobación de la restauración detecta: {nombre}")
    deshacer = pf.BACKUPS / "2026-10-28-130000" / "claves-previas.json"
    ok(deshacer.is_file() and json.loads(deshacer.read_text(encoding="utf-8"))["claves"]["admin_password_hash"] == "'" + VALORES["admin_password_hash"] + "'"
       and "sk-ficticia" not in deshacer.read_text(encoding="utf-8"), "antes guarda los valores que había (se puede deshacer), sin el resto del config")
    # El config remoto cambió después de --subir: se reponen solo las dos claves y lo nuevo se conserva.
    cambiado = final.replace("'limite' => 5,", "'limite' => 9,").replace("];", "    'clave_nueva' => 'despues-de-subir',\n];")
    ftp = FtpFalso({REM: cambiado.encode()}, {REM: "0640"})
    r, out = ejecutar(hc.cmd_restaurar, args(restaurar_config=str(pf.BACKUPS / sello), aplicar=True), pf, ftp_factory=lambda: ftp, ahora=datetime(2026, 10, 28, 14, 0, 0))
    res = ftp.archivos[REM].decode()
    ok(r == 0 and VIEJO in res and VALORES["admin_password_hash"] not in res and "'panel_sal'" not in res
       and "'limite' => 9," in res and "'clave_nueva' => 'despues-de-subir'" in res and ftp.modos[REM] == "0640",
       "con el config remoto cambiado después, repone solo esas dos claves y respeta lo demás", out)
    # Las dos claves existían antes (CRLF y sal con comillas dobles): se reponen ambas tal cual.
    previo2 = crlf.replace("'vieja-sal-ficticia-de-32-caracteres-xx'", '"vieja-sal-ficticia-de-32-caracteres-xx"')
    ftp = FtpFalso({REM: previo2.encode()}, {REM: "0600"})
    ejecutar(hc.cmd_subir, args(aplicar=True), pf, ftp_factory=lambda: ftp, pedir=lambda: dict(VALORES), ahora=datetime(2026, 10, 28, 15, 0, 0))
    ok(ftp.archivos[REM] != previo2.encode(), "(preparación) --subir cambió las dos claves")
    r, out = ejecutar(hc.cmd_restaurar, args(restaurar_config=str(pf.BACKUPS / "2026-10-28-150000"), aplicar=True), pf, ftp_factory=lambda: ftp, ahora=datetime(2026, 10, 28, 15, 5, 0))
    ok(r == 0 and ftp.archivos[REM] == previo2.encode(), "si las dos claves existían, la restauración deja el archivo byte a byte como estaba", out)
    # Fallos que no tocan el servidor
    for nombre, contenido in (("claves-previas.json sin las dos claves", {"claves": {"admin_password_hash": "'x'"}}),
                              ("un valor que no es un literal PHP simple", {"claves": {"admin_password_hash": "'a' . system('id') . 'b'", "panel_sal": None}})):
        malo = pf.BACKUPS / "malo"
        malo.mkdir(exist_ok=True)
        (malo / "claves-previas.json").write_text(json.dumps(contenido), encoding="utf-8")
        (malo / "manifiesto.json").write_text("{}", encoding="utf-8")
        ftp = FtpFalso({REM: final.encode()}, {REM: "0600"})
        r, out = ejecutar(hc.cmd_restaurar, args(restaurar_config=str(malo), aplicar=True), pf, ftp_factory=lambda: ftp, ahora=AHORA)
        ok(isinstance(r, str) and ftp.archivos == {REM: final.encode()} and ftp.ops == [], f"rechaza {nombre} sin conectar")
    ftp = FtpFalso({REM: final.encode()}, {REM: "0600"})
    r, out = ejecutar(hc.cmd_restaurar, args(restaurar_config=str(pf.BACKUPS / "no-existe"), aplicar=True), pf, ftp_factory=lambda: ftp, ahora=AHORA)
    ok(isinstance(r, str) and ftp.ops == [], "una carpeta sin claves-previas.json se rechaza")

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
    ok("credencial.json" in c.stdout and "queda sin efecto" in c.stdout,
       "tarea 54: --subir avisa de que deja sin efecto la contraseña cambiada en el panel", c.stdout)
    ok("credencial.json" in (hc.__doc__ or "") and "manda" in (hc.__doc__ or "") and "vía de recuperación" in (hc.__doc__ or ""),
       "tarea 54: la ayuda explica qué contraseña manda (panel o config)")
    c = cli("--subir", "--aplicar")
    ok(c.returncode != 0 and "--aprobado-por" in c.stderr, "--aplicar sin --aprobado-por se niega")
    c = cli("--aplicar", "--aprobado-por", "X")
    ok(c.returncode != 0 and "solo valen con --subir" in c.stderr, "--aplicar sin --subir se niega")
    c = cli("--subir", "--sin-red", "--aplicar", "--aprobado-por", "X")
    ok(c.returncode != 0 and "--sin-red solo vale en simulación" in c.stderr, "--sin-red no vale al aplicar")
    c = cli("--solo-sal")
    ok(c.returncode != 0 and "--solo-sal solo vale con --subir" in c.stderr, "--solo-sal sin --subir se niega")
    c = cli("--subir", "--forzar")
    ok(c.returncode != 0 and "--forzar solo vale con --solo-sal" in c.stderr, "--forzar sin --solo-sal se niega")
    c = cli("--subir", "--solo-sal", "--bcrypt")
    ok(c.returncode != 0 and "--bcrypt" in c.stderr, "--solo-sal con --bcrypt se niega")
    c = cli("--subir", "--solo-sal", "--sin-red")
    ok(c.returncode == 0 and "solo la sal" in c.stdout and "panel_sal" in c.stdout and "admin_password_hash:" not in c.stdout and "credencial.json" not in c.stdout,
       "--solo-sal --subir --sin-red simula sin conectar y solo habla de panel_sal", c.stdout + c.stderr)
    c = cli("--subir", "--solo-sal", "--aplicar", "--aprobado-por", "X", "--sin-red")
    ok(c.returncode != 0, "--solo-sal con --aplicar --sin-red se niega")

    print("9. --solo-sal: añade panel_sal sin tocar la contraseña")
    CLAVE_P = "clave-ficticia-solo-sal-2026"
    HASH_REAL = hc.generar_hash(PHP, CLAVE_P.encode(), True)
    CFG = CONFIG.replace(VIEJO, HASH_REAL)
    CFG_SAL = CFG.replace("'limite' => 5\n", "'limite' => 5,\n    'panel_sal' => 'SalAnteriorFicticia_0123456789-abcdefghijABCDEFGHIJ0123456789xyz',\n")
    SAL_VIEJA = "SalAnteriorFicticia_0123456789-abcdefghijABCDEFGHIJ0123456789xyz"
    original_getpass = getpass.getpass
    def sin_getpass(prompt=""):
        raise AssertionError("--solo-sal no debe pedir contraseña")
    getpass.getpass = sin_getpass
    try:
        # simulación
        ftp = FtpFalso({REM: CFG.encode()}, {REM: "0640"})
        r, out = ejecutar(hc.cmd_subir, args(solo_sal=True), pf, ftp_factory=lambda: ftp, ahora=AHORA)
        ok(r == 0 and "solo la sal" in out and "panel_sal: no existe -> se añadiría" in out
           and "admin_password_hash: existe y password_verify lo acepta" in out and "AVISO" not in out, "simula: dice que la sal se añadiría y que el hash es válido", out)
        ok("admin_password_hash: existe -> se sustituiría" not in out and HASH_REAL not in out and "sk-ficticia" not in out and "admin@ejemplo.test" not in out,
           "no promete cambiar el hash ni muestra valores")
        ok([o[0] for o in ftp.ops] == ["RETR"] and ftp.archivos == {REM: CFG.encode()} and ftp.cerrado, "simulación: solo lectura", str(ftp.ops))
        # aplicar
        ftp = FtpFalso({REM: CFG.encode(), "/public_html/index.html": b"web"}, {REM: "0640"})
        sello9 = datetime(2026, 10, 28, 16, 0, 0).strftime("%Y-%m-%d-%H%M%S")
        r, out = ejecutar(hc.cmd_subir, args(solo_sal=True, aplicar=True), pf, ftp_factory=lambda: ftp, ahora=datetime(2026, 10, 28, 16, 0, 0))
        fin = ftp.archivos[REM].decode()
        m = re.search(r"'panel_sal' => '([A-Za-z0-9_-]{64})'", fin)
        ok(r == 0 and m is not None and fin.count("'panel_sal'") == 1, "añade una panel_sal de 64 caracteres URL-safe (mismo formato)", out)
        quitada = fin.replace(f"    'panel_sal' => '{m[1]}',\n", "").replace("'limite' => 5,", "'limite' => 5") if m else ""
        ok(quitada == CFG and f"'admin_password_hash' => '{HASH_REAL}'," in fin, "admin_password_hash y el resto del archivo quedan byte a byte iguales")
        try:
            hc.comprobar(PHP, CFG, fin, {"panel_sal": m[1]}, ("panel_sal",))
            ok(True, "PHP confirma: solo panel_sal es nueva, las demás claves idénticas")
        except (ValueError, TypeError) as e:
            ok(False, "PHP confirma: solo panel_sal es nueva, las demás claves idénticas", str(e))
        a_ = hc._resumen(PHP, fin)
        ok(set(a_) == set(hc._resumen(PHP, CFG)) | {"panel_sal"}, "PHP: se añadió una sola clave")
        ok(ftp.modos[REM] == "0640" and ftp.archivos["/public_html/index.html"] == b"web" and not any(k.startswith(REM + ".tmp") for k in ftp.archivos),
           "conserva permisos (0640), no deja temporal y no toca nada más")
        cop = pf.BACKUPS / sello9 / "claves-previas.json"
        txt = cop.read_text(encoding="utf-8") if cop.is_file() else ""
        ok(cop.is_file() and json.loads(txt)["claves"] == {"panel_sal": None}, "la copia previa solo guarda panel_sal = null")
        mf9 = (pf.BACKUPS / sello9 / "manifiesto.json").read_text(encoding="utf-8")
        ok(m and all(s not in txt + mf9 + out for s in (m[1], HASH_REAL, "sk-ficticia")), "nunca se muestra ni se guarda la sal nueva ni el hash")
        ok("tu contraseña de siempre" in out and "--restaurar-config" in out and "--solo-sal" in mf9, "explica que la contraseña no cambia y cómo volver atrás")
        # restaurar la copia de solo-sal deja el archivo como estaba
        r2, out2 = ejecutar(hc.cmd_restaurar, args(restaurar_config=str(pf.BACKUPS / sello9), aplicar=True), pf, ftp_factory=lambda: ftp, ahora=datetime(2026, 10, 28, 16, 30, 0))
        ok(r2 == 0 and ftp.archivos[REM] == CFG.replace("'limite' => 5\n", "'limite' => 5,\n").encode() and ftp.modos[REM] == "0640" and "'panel_sal'" not in ftp.archivos[REM].decode(),
           "--restaurar-config sobre esa copia quita la sal (queda la coma final) y conserva permisos", out2)
        # ya existe: no hace nada
        ftp = FtpFalso({REM: CFG_SAL.encode()}, {REM: "0600"})
        for ap in (False, True):
            r, out = ejecutar(hc.cmd_subir, args(solo_sal=True, aplicar=ap), pf, ftp_factory=lambda: ftp, ahora=datetime(2026, 10, 28, 17, 0, 0))
            ok(r == 0 and "panel_sal: ya existe -> no se hace nada" in out and all(o[0] == "RETR" for o in ftp.ops),
               f"si panel_sal ya existe no hace nada ({'aplicar' if ap else 'simular'})", out + str(ftp.ops))
            ok(ftp.archivos == {REM: CFG_SAL.encode()} and SAL_VIEJA not in out, "  el archivo no cambia y no muestra la sal")
        ok(not (pf.BACKUPS / "2026-10-28-170000").exists(), "  sin copia previa si no hay cambio")
        # --forzar
        r, out = ejecutar(hc.cmd_subir, args(solo_sal=True, forzar=True, aplicar=True), pf, ftp_factory=lambda: ftp, ahora=datetime(2026, 10, 28, 18, 0, 0))
        fin2 = ftp.archivos[REM].decode()
        m2 = re.search(r"'panel_sal' => '([A-Za-z0-9_-]{64})'", fin2)
        ok(r == 0 and m2 and m2[1] != SAL_VIEJA and SAL_VIEJA not in fin2 and fin2.count("'panel_sal'") == 1 and f"'{HASH_REAL}'" in fin2,
           "--forzar sustituye la sal existente y deja el hash igual", out)
        cop2 = (pf.BACKUPS / "2026-10-28-180000" / "claves-previas.json").read_text(encoding="utf-8")
        ok(json.loads(cop2)["claves"] == {"panel_sal": "'" + SAL_VIEJA + "'"} and HASH_REAL not in cop2 and m2[1] not in cop2 + out,
           "la copia previa solo lleva la sal anterior; la nueva no se muestra")
        r2, _ = ejecutar(hc.cmd_restaurar, args(restaurar_config=str(pf.BACKUPS / "2026-10-28-180000"), aplicar=True), pf, ftp_factory=lambda: ftp, ahora=datetime(2026, 10, 28, 18, 30, 0))
        ok(r2 == 0 and ftp.archivos[REM] == CFG_SAL.encode(), "restaurar tras --forzar deja la sal anterior byte a byte")
        # hash no válido
        for malo, nombre in ((CFG.replace(HASH_REAL, "contrasena-en-texto-plano"), "texto plano"),
                             (CFG.replace(HASH_REAL, "$1$viejo$abcdefghijklmnopqrstuv"), "formato MD5-crypt no admitido"),
                             (CFG.replace(f"    'admin_password_hash' => '{HASH_REAL}',\n", ""), "sin la clave")):
            ftp = FtpFalso({REM: malo.encode()}, {REM: "0600"})
            r, out = ejecutar(hc.cmd_subir, args(solo_sal=True), pf, ftp_factory=lambda: ftp, ahora=AHORA)
            ok(r == 0 and "AVISO: admin_password_hash" in out and "contrasena-en-texto-plano" not in out and "$1$viejo" not in out,
               f"avisa sin mostrar el valor: {nombre}", out)
        ok(hc.comprobar_hash_existente(PHP, CFG)[0] and hc.comprobar_hash_existente(PHP, CONFIG)[0], "reconoce hashes $2y$ válidos")
        # el config del servidor no se puede cambiar con seguridad
        ftp = FtpFalso({REM: CFG.replace("];", "    'panel_sal' => 'a',\n    'panel_sal' => 'b',\n];").encode()}, {REM: "0600"})
        r, out = ejecutar(hc.cmd_subir, args(solo_sal=True, aplicar=True), pf, ftp_factory=lambda: ftp, ahora=datetime(2026, 10, 28, 19, 0, 0))
        ok(isinstance(r, str) and "a mano" in r and [o[0] for o in ftp.ops] == ["RETR"], "panel_sal repetida: no se toca nada", str(r))
    finally:
        getpass.getpass = original_getpass
finally:
    shutil.rmtree(TMP, ignore_errors=True)

print(f"\n{total - fallos}/{total} comprobaciones correctas" if not fallos else f"\nFALLAN {fallos} de {total}")
sys.exit(1 if fallos else 0)
