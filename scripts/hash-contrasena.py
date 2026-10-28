#!/usr/bin/env python3
"""Genera en TU ordenador el hash de la contraseña del panel (R1) y la sal del panel (R12).

La contraseña se teclea aquí (no se ve en pantalla), no se guarda en ningún archivo, no sale del PC y
no se pasa como argumento (llega a PHP por la entrada estándar). Nunca la pegues en un chat.

Uso (desde la raíz del repositorio):
  python scripts/hash-contrasena.py                 # argon2id (recomendado)
  python scripts/hash-contrasena.py --bcrypt        # bcrypt coste 12, solo si el hosting no tiene argon2id
  python scripts/hash-contrasena.py --php "C:/ruta/php.exe"

Qué imprime: dos líneas para pegar en el servidor, en dl-auditoria-config.php (carpeta personal, al lado
de public_html, NUNCA dentro), sustituyendo las que haya con el mismo nombre:
  'admin_password_hash' => '…',
  'panel_sal' => '…',
El correo de acceso sigue en 'admin_email'. Ver docs/traspasos/20-tarea-50-panel-leads.md (proyecto leads).
"""
import argparse
import getpass
import os
import secrets
import shutil
import subprocess
import sys

MIN_CARACTERES = 14
MAX_BYTES = 1024
PHP_LOCAL = r"C:\Users\Win10\herramientas\php83\php.exe"

CODIGO_PHP = r"""
$p = stream_get_contents(STDIN);
$bcrypt = ($argv[1] ?? '') === 'bcrypt';
if (!$bcrypt && !defined('PASSWORD_ARGON2ID')) { fwrite(STDERR, "Este PHP no tiene argon2id: usa --bcrypt\n"); exit(2); }
$h = $bcrypt ? password_hash($p, PASSWORD_BCRYPT, ['cost' => 12]) : password_hash($p, PASSWORD_ARGON2ID);
if (!is_string($h) || !password_verify($p, $h)) { fwrite(STDERR, "No se pudo generar el hash\n"); exit(3); }
echo $h;
"""


def buscar_php(arg: str | None) -> str:
    for c in (arg, os.environ.get("DL_PHP"), PHP_LOCAL if os.path.isfile(PHP_LOCAL) else None, shutil.which("php")):
        if c:
            return c
    sys.exit("No encuentro PHP. Indica la ruta con --php.")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--bcrypt", action="store_true", help="bcrypt coste 12 en lugar de argon2id")
    ap.add_argument("--php", help="ruta del ejecutable de PHP")
    a = ap.parse_args()

    print("Contraseña nueva del panel: 14 caracteres o más, única (no la uses en ningún otro sitio).")
    p1 = getpass.getpass("Contraseña (no se ve al escribir): ")
    p2 = getpass.getpass("Repítela: ")
    if p1 != p2:
        sys.exit("No coinciden. No se ha generado nada.")
    if len(p1) < MIN_CARACTERES:
        sys.exit(f"Tiene {len(p1)} caracteres; hacen falta {MIN_CARACTERES} o más. No se ha generado nada.")
    datos = p1.encode("utf-8")
    if len(datos) > MAX_BYTES:
        sys.exit(f"Ocupa {len(datos)} bytes; el máximo es {MAX_BYTES}. No se ha generado nada.")
    if len(set(p1)) < 6:
        sys.exit("Repite demasiado los mismos caracteres. Elige otra. No se ha generado nada.")

    r = subprocess.run([buscar_php(a.php), "-r", CODIGO_PHP, "bcrypt" if a.bcrypt else "argon2id"],
                       input=datos, capture_output=True)
    del p1, p2, datos
    if r.returncode != 0:
        sys.exit(r.stderr.decode("utf-8", "replace").strip() or "PHP falló.")
    h = r.stdout.decode("ascii").strip()
    if "'" in h or not h.startswith("$"):
        sys.exit("Hash inesperado. No lo uses.")

    print("\nPega estas dos líneas en dl-auditoria-config.php del servidor (sustituyendo las anteriores):\n")
    print(f"    'admin_password_hash' => '{h}',")
    print(f"    'panel_sal' => '{secrets.token_urlsafe(48)}',")
    print("\nNo guardes esta salida en ningún archivo del proyecto ni la pegues en un chat.")


if __name__ == "__main__":
    main()
