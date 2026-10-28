#!/usr/bin/env python3
"""Genera en TU ordenador el hash de la contraseña del panel (R1) y la sal del panel (R12) y, con --subir,
los pone en dl-auditoria-config.php del servidor sin que tengas que copiarlos a mano.

La contraseña se teclea aquí (no se ve en pantalla), no se guarda en ningún archivo, no sale del PC y
no se pasa como argumento (llega a PHP por la entrada estándar). Nunca la pegues en un chat.

Uso (desde la raíz del repositorio):
  python scripts/hash-contrasena.py                 # argon2id (recomendado): imprime las dos líneas
  python scripts/hash-contrasena.py --bcrypt        # bcrypt coste 12, solo si el hosting no tiene argon2id
  python scripts/hash-contrasena.py --php "C:/ruta/php.exe"

  python scripts/hash-contrasena.py --subir                                   # SIMULA (por defecto)
  python scripts/hash-contrasena.py --subir --sin-red                         # simula sin conectar
  python scripts/hash-contrasena.py --subir --aplicar --aprobado-por "Leandro"
  python scripts/hash-contrasena.py --restaurar-config docs/backups/<carpeta>  # simula volver atrás
  python scripts/hash-contrasena.py --restaurar-config docs/backups/<carpeta> --aplicar --aprobado-por "Leandro"

Sin --subir imprime dos líneas para pegar en dl-auditoria-config.php (carpeta personal, al lado de
public_html, NUNCA dentro), sustituyendo las que haya con el mismo nombre:
  'admin_password_hash' => '…',
  'panel_sal' => '…',

--subir (tarea 51) usa la conexión FTPS y las credenciales (.env) de scripts/publicar-ftp.py del proyecto
leads (clase Ftp; otra ruta con --publicador o DL_PUBLICADOR):
  1. Descarga /dl-auditoria-config.php (en memoria) y mira qué claves cambiarían. La simulación termina aquí:
     dice «se sustituiría» o «se añadiría» por clave y los permisos actuales, nunca valores.
  2. Al aplicar: pide la contraseña con getpass, genera el hash y una sal nueva, sustituye o añade SOLO
     admin_password_hash y panel_sal y comprueba con PHP que el archivo sigue siendo válido y que todas las
     demás claves quedan idénticas.
  3. Guarda copia del archivo anterior en docs/backups/<fecha-hora>/ del proyecto leads (fuera de Git) con
     un manifiesto sin secretos.
  4. Sube a un temporal, le pone los mismos permisos que tenía, comprueba tamaño y permisos y lo renombra.
El correo de acceso sigue en 'admin_email'. Ver docs/traspasos/20-tarea-50-panel-leads.md (proyecto leads).
"""
import argparse
import getpass
import hashlib
import importlib.util
import json
import os
import re
import secrets
import shutil
import subprocess
import sys
from datetime import datetime
from pathlib import Path

MIN_CARACTERES = 14
MAX_BYTES = 1024
PHP_LOCAL = r"C:\Users\Win10\herramientas\php83\php.exe"
CLAVES = ("admin_password_hash", "panel_sal")
CONFIG_REMOTA = "/dl-auditoria-config.php"
NOMBRE_COPIA = "dl-auditoria-config.php"
PUBLICADOR = Path(os.environ.get("DL_PUBLICADOR")
                  or r"D:\Claude\Web Digital Lab\empresa\proyectos\leads\scripts\publicar-ftp.py")

CODIGO_PHP = r"""
$p = stream_get_contents(STDIN);
$bcrypt = ($argv[1] ?? '') === 'bcrypt';
if (!$bcrypt && !defined('PASSWORD_ARGON2ID')) { fwrite(STDERR, "Este PHP no tiene argon2id: usa --bcrypt\n"); exit(2); }
$h = $bcrypt ? password_hash($p, PASSWORD_BCRYPT, ['cost' => 12]) : password_hash($p, PASSWORD_ARGON2ID);
if (!is_string($h) || !password_verify($p, $h)) { fwrite(STDERR, "No se pudo generar el hash\n"); exit(3); }
echo $h;
"""

# Evalúa la configuración (llega por la entrada estándar, nunca a disco) y devuelve sha256(serialize(valor))
# por clave: así se comparan el archivo anterior y el nuevo sin que ningún valor salga por pantalla.
CODIGO_RESUMEN = r"""
defined('DL_AUDIT') || define('DL_AUDIT', 1);
defined('DL_PANEL') || define('DL_PANEL', 1);
$src = stream_get_contents(STDIN);
try { $c = eval('?>' . $src); } catch (Throwable $e) { fwrite(STDERR, "no es PHP válido\n"); exit(3); }
if (!is_array($c)) { fwrite(STDERR, "no devuelve un array\n"); exit(4); }
$o = [];
foreach ($c as $k => $v) $o[(string) $k] = hash('sha256', serialize($v));
echo json_encode($o);
"""

_LITERAL = r"""'(?:[^'\\]|\\.)*'|"(?:[^"\\$]|\\.)*\""""
_VALOR_SEGURO = re.compile(r"[A-Za-z0-9$./+=,_-]{20,400}")   # cabe entre comillas simples de PHP tal cual
_MODO = re.compile(r"0?[0-7]{3}")


def sha(b: bytes) -> str:
    return hashlib.sha256(b).hexdigest()[:12]


def buscar_php(arg: str | None) -> str:
    for c in (arg, os.environ.get("DL_PHP"), PHP_LOCAL if os.path.isfile(PHP_LOCAL) else None, shutil.which("php")):
        if c:
            return c
    sys.exit("No encuentro PHP. Indica la ruta con --php.")


# ---------- contraseña, hash y sal ----------

def pedir_contrasena() -> bytes:
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
    return datos


def generar_hash(php: str, datos: bytes, bcrypt: bool) -> str:
    r = subprocess.run([php, "-r", CODIGO_PHP, "bcrypt" if bcrypt else "argon2id"], input=datos, capture_output=True)
    if r.returncode != 0:
        sys.exit(r.stderr.decode("utf-8", "replace").strip() or "PHP falló.")
    h = r.stdout.decode("ascii").strip()
    if "'" in h or not h.startswith("$") or not _VALOR_SEGURO.fullmatch(h):
        sys.exit("Hash inesperado. No lo uses.")
    return h


def generar_valores(php: str, bcrypt: bool) -> dict:
    """Pide la contraseña y devuelve {admin_password_hash, panel_sal}. La contraseña no sale de aquí."""
    datos = pedir_contrasena()
    try:
        h = generar_hash(php, datos, bcrypt)
    finally:
        del datos
    return {"admin_password_hash": h, "panel_sal": secrets.token_urlsafe(48)}


# ---------- cambiar solo dos claves de la configuración ----------

def _patron(clave: str):
    return re.compile(r"^(?P<pre>[ \t]*(['\"])" + re.escape(clave) + r"\2[ \t]*=>[ \t]*)(?P<val>" + _LITERAL + ")", re.M)


def _cierre(texto: str):
    """Posición del «];» (o «);») final del array devuelto, al principio de su línea si va solo."""
    m = re.search(r"[\])]\s*;\s*(?:\?>\s*)?\Z", texto)
    if not m:
        return None
    pos = m.start()
    ini = texto.rfind("\n", 0, pos) + 1
    return ini if texto[ini:pos].strip() == "" else pos


def analizar(texto: str) -> dict:
    """{clave: 'sustituir' | 'añadir'}. ValueError si no se puede cambiar con seguridad (mejor a mano)."""
    plan = {}
    for clave in CLAVES:
        menciones = len(re.findall(r"['\"]" + re.escape(clave) + r"['\"]", texto))
        lineas = list(_patron(clave).finditer(texto))
        if menciones == 0:
            plan[clave] = "añadir"
        elif menciones == 1 and len(lineas) == 1:
            plan[clave] = "sustituir"
        else:
            raise ValueError(f"«{clave}» aparece {menciones} veces o su valor no es un texto simple: cámbialo a mano")
    if "añadir" in plan.values() and _cierre(texto) is None:
        raise ValueError("no encuentro el «];» final del array para añadir claves: cámbialo a mano")
    return plan


def sustituir(texto: str, valores: dict) -> str:
    plan = analizar(texto)
    for clave in CLAVES:
        if not isinstance(valores.get(clave), str) or not _VALOR_SEGURO.fullmatch(valores[clave]):
            raise ValueError(f"valor inesperado para «{clave}»")
    nuevo = texto
    for clave in CLAVES:
        if plan[clave] == "sustituir":
            m = _patron(clave).search(nuevo)
            nuevo = nuevo[:m.start("val")] + "'" + valores[clave] + "'" + nuevo[m.end("val"):]
    faltan = [c for c in CLAVES if plan[c] == "añadir"]
    if faltan:
        nl = "\r\n" if "\r\n" in nuevo else "\n"
        pos = _cierre(nuevo)
        antes = nuevo[:pos].rstrip()
        if not antes.endswith((",", "[", "(")):
            antes += ","
        lineas = "".join(f"    '{c}' => '{valores[c]}',{nl}" for c in faltan)
        nuevo = antes + nl + lineas + nuevo[pos:]
    return nuevo


def _resumen(php: str, texto: str) -> dict:
    r = subprocess.run([php, "-r", CODIGO_RESUMEN], input=texto.encode("utf-8"), capture_output=True, timeout=60)
    if r.returncode != 0:
        raise ValueError("PHP no lo acepta (" + r.stderr.decode("utf-8", "replace").strip()[:80] + ")")
    return json.loads(r.stdout.decode("utf-8"))


def _sha_php_texto(v: str) -> str:
    return hashlib.sha256(f's:{len(v.encode("utf-8"))}:"{v}";'.encode("utf-8")).hexdigest()


def comprobar(php: str, original: str, nuevo: str, valores: dict) -> None:
    """El archivo nuevo es PHP válido, tiene los dos valores nuevos y el resto de claves idéntico."""
    a = _resumen(php, original)
    b = _resumen(php, nuevo)
    if set(b) != set(a) | set(CLAVES):
        raise ValueError("cambiarían otras claves además de las dos del panel")
    distintas = [k for k in a if k not in CLAVES and a[k] != b[k]]
    if distintas:
        raise ValueError(f"cambiaría el valor de {len(distintas)} clave(s) que no son del panel")
    if any(b[k] != _sha_php_texto(valores[k]) for k in CLAVES):
        raise ValueError("los valores nuevos no quedaron bien escritos")


# ---------- servidor ----------

def cargar_publicador(ruta: Path):
    if not ruta.is_file():
        sys.exit(f"No encuentro {ruta} (scripts/publicar-ftp.py del proyecto leads). Indica otra con --publicador.")
    spec = importlib.util.spec_from_file_location("publicar_ftp", ruta)
    pf = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(pf)
    return pf


def subir_config(ftp, datos: bytes, modo: str, sello: str, remota: str = CONFIG_REMOTA) -> None:
    """Temporal + mismos permisos + comprobación + renombrar. Si algo falla antes de renombrar, nada cambia."""
    tmp = f"{remota}.tmp-{sello}"
    ftp.subir(datos, tmp)
    try:
        ftp.f.sendcmd(f"SITE CHMOD {modo[-3:]} {tmp}")
        info = ftp.info(tmp)
        m = ftp.modo(tmp)
        if not info or info[0] != len(datos) or m[-3:] != modo[-3:]:
            raise RuntimeError(f"comprobación del temporal fallida (tamaño o permisos {m})")
    except Exception:
        ftp.borrar(tmp)
        raise
    ftp.renombrar(tmp, remota)


def _leer_remota(ftp):
    if not ftp.info(CONFIG_REMOTA):
        sys.exit(f"{CONFIG_REMOTA} no existe en el servidor: no se crea desde aquí (lleva admin_email y más claves).")
    modo = ftp.modo(CONFIG_REMOTA)
    datos = ftp.leer(CONFIG_REMOTA)
    try:
        texto = datos.decode("utf-8")
    except UnicodeDecodeError:
        sys.exit(f"{CONFIG_REMOTA} no está en UTF-8: cámbialo a mano.")
    return modo, datos, texto


def cmd_subir(a, pf, ftp_factory=None, pedir=None, ahora=None) -> int:
    aplicar = a.aplicar
    print(f"Modo: {'APLICAR' if aplicar else 'SIMULAR (no se escribe nada ni se pide la contraseña)'}")
    print(f"Destino: {CONFIG_REMOTA} (carpeta personal del FTP, al lado de public_html)")
    if a.sin_red:
        print("(sin red: no se consulta el servidor)")
        for c in CLAVES:
            print(f"- {c}: se sustituiría si existe o se añadiría al final del array (sin mostrar valores)")
        print("Las demás claves no se tocan; los permisos del archivo se conservan.")
        print("\nSimulación terminada: no se subió ni se guardó nada.")
        return 0
    php = buscar_php(a.php)
    ftp = ftp_factory() if ftp_factory else pf.Ftp(pf.cargar_env())
    sello = (ahora or datetime.now()).strftime("%Y-%m-%d-%H%M%S")
    valores = None
    try:
        modo, original, texto = _leer_remota(ftp)
        print(f"Ahora: {len(original)} bytes, sha256 {sha(original)}, permisos {modo}")
        try:
            plan = analizar(texto)
        except ValueError as e:
            sys.exit(f"No se cambia: {e}.")
        for c in CLAVES:
            print(f"- {c}: " + ("existe -> se sustituiría su valor" if plan[c] == "sustituir" else "no existe -> se añadiría"))
        print("- resto de claves: sin cambios (se comprueba con PHP antes de subir)")
        if not _MODO.fullmatch(modo or ""):
            print(f"  AVISO: el servidor no informa de los permisos ({modo}); al aplicar me detendría.")
        copia = pf.BACKUPS / sello / NOMBRE_COPIA
        print(f"Copia previa -> {copia}")
        print(f"Subida: {CONFIG_REMOTA}.tmp-{sello} -> SITE CHMOD {modo[-3:] if modo else '?'} -> comprobar -> renombrar")
        if not aplicar:
            print("\nSimulación terminada: no se subió ni se guardó nada.")
            return 0
        # --- aplicar ---
        if not _MODO.fullmatch(modo or ""):
            sys.exit("No se aplica: no sé qué permisos tiene el archivo y no los puedo conservar.")
        valores = (pedir or (lambda: generar_valores(php, a.bcrypt)))()
        ftp.secretos.extend(valores.values())          # por si un error del servidor los repitiera
        try:
            nuevo = sustituir(texto, valores)
            comprobar(php, texto, nuevo, valores)
        except ValueError as e:
            sys.exit(f"No se sube: {e}. Nada cambió.")
        nuevo_b = nuevo.encode("utf-8")
        copia.parent.mkdir(parents=True, exist_ok=True)
        copia.write_bytes(original)
        if copia.read_bytes() != original:
            sys.exit("La copia de seguridad no coincide: me detengo sin subir.")
        (copia.parent / "manifiesto.json").write_text(json.dumps({
            "remota": CONFIG_REMOTA, "modo": modo, "sha_previo": sha(original), "sha_nuevo": sha(nuevo_b),
            "claves": plan, "aprobado_por": a.aprobado_por.strip(), "script": "hash-contrasena.py --subir",
        }, indent=2, ensure_ascii=False), encoding="utf-8")
        if ftp.leer(CONFIG_REMOTA) != original:
            sys.exit("El archivo del servidor cambió mientras tanto: no se sube. Vuelve a ejecutarlo.")
        try:
            subir_config(ftp, nuevo_b, modo, sello)
        except Exception as e:
            sys.exit(f"Fallo al subir: {ftp.limpio(e)}. El archivo del servidor no cambió.")
        print(f"HECHO: {CONFIG_REMOTA} {ftp.info(CONFIG_REMOTA)[0]} bytes, permisos {ftp.modo(CONFIG_REMOTA)}")
        print(f"Copia del anterior en {copia.parent}")
        print(f"Para volver atrás: python scripts/hash-contrasena.py --restaurar-config \"{copia.parent}\" --aplicar --aprobado-por \"<nombre>\"")
        print("Prueba ahora a entrar en /login/ con la contraseña nueva. Las sesiones abiertas siguen hasta que caduquen.")
        return 0
    finally:
        valores = None
        ftp.cerrar()


def cmd_restaurar(a, pf, ftp_factory=None, ahora=None) -> int:
    carpeta = Path(a.restaurar_config)
    if not carpeta.is_absolute():
        carpeta = pf.PROYECTO / carpeta
    copia, mf = carpeta / NOMBRE_COPIA, carpeta / "manifiesto.json"
    if not copia.is_file() or not mf.is_file():
        sys.exit(f"En {carpeta} no hay {NOMBRE_COPIA} y manifiesto.json de hash-contrasena.py.")
    datos = copia.read_bytes()
    print(f"Modo: {'APLICAR restauración' if a.aplicar else 'SIMULAR restauración (no se escribe nada)'}")
    print(f"Se repondría {CONFIG_REMOTA} con la copia de {carpeta.name} ({len(datos)} bytes, sha256 {sha(datos)})")
    ftp = ftp_factory() if ftp_factory else pf.Ftp(pf.cargar_env())
    sello = (ahora or datetime.now()).strftime("%Y-%m-%d-%H%M%S")
    try:
        modo, actual, _ = _leer_remota(ftp)
        print(f"Ahora: {len(actual)} bytes, sha256 {sha(actual)}, permisos {modo}" + (" (= idéntico a la copia)" if actual == datos else ""))
        nueva = pf.BACKUPS / sello / NOMBRE_COPIA
        print(f"Copia del actual -> {nueva} (por si hay que deshacer la restauración)")
        if not a.aplicar:
            print("\nSimulación terminada: no se cambió nada.")
            return 0
        if not _MODO.fullmatch(modo or ""):
            sys.exit("No se aplica: no sé qué permisos tiene el archivo y no los puedo conservar.")
        nueva.parent.mkdir(parents=True, exist_ok=True)
        nueva.write_bytes(actual)
        (nueva.parent / "manifiesto.json").write_text(json.dumps({
            "remota": CONFIG_REMOTA, "modo": modo, "sha_previo": sha(actual), "sha_nuevo": sha(datos),
            "aprobado_por": a.aprobado_por.strip(), "script": "hash-contrasena.py --restaurar-config " + carpeta.name,
        }, indent=2, ensure_ascii=False), encoding="utf-8")
        try:
            subir_config(ftp, datos, modo, sello)
        except Exception as e:
            sys.exit(f"Fallo al subir: {ftp.limpio(e)}. El archivo del servidor no cambió.")
        print(f"HECHO: repuesto ({ftp.info(CONFIG_REMOTA)[0]} bytes, permisos {ftp.modo(CONFIG_REMOTA)})")
        return 0
    finally:
        ftp.cerrar()


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--bcrypt", action="store_true", help="bcrypt coste 12 en lugar de argon2id")
    ap.add_argument("--php", help="ruta del ejecutable de PHP")
    ap.add_argument("--subir", action="store_true", help="pone hash y sal en dl-auditoria-config.php del servidor (simula salvo --aplicar)")
    ap.add_argument("--restaurar-config", metavar="CARPETA", help="repone la copia guardada por --subir (simula salvo --aplicar)")
    ap.add_argument("--aplicar", action="store_true", help="con --subir o --restaurar-config: escribe de verdad; exige --aprobado-por")
    ap.add_argument("--aprobado-por", default="", help="nombre de la persona que aprobó el cambio")
    ap.add_argument("--sin-red", action="store_true", help="con --subir, simula sin conectar al servidor")
    ap.add_argument("--publicador", type=Path, default=PUBLICADOR, help=f"ruta de publicar-ftp.py (por defecto {PUBLICADOR})")
    a = ap.parse_args()

    if a.subir and a.restaurar_config:
        sys.exit("--subir y --restaurar-config son incompatibles.")
    remoto = a.subir or bool(a.restaurar_config)
    if (a.aplicar or a.sin_red) and not remoto:
        sys.exit("--aplicar y --sin-red solo valen con --subir o --restaurar-config.")
    if a.aplicar and not a.aprobado_por.strip():
        sys.exit("Para aplicar hace falta --aprobado-por \"<nombre>\": el cambio lo aprueba una persona.")
    if a.aplicar and a.sin_red:
        sys.exit("--sin-red solo vale en simulación.")
    if a.sin_red and a.restaurar_config:
        sys.exit("--sin-red solo vale con --subir.")
    if a.subir:
        sys.exit(cmd_subir(a, None if a.sin_red else cargar_publicador(a.publicador)))
    if a.restaurar_config:
        sys.exit(cmd_restaurar(a, cargar_publicador(a.publicador)))

    valores = generar_valores(buscar_php(a.php), a.bcrypt)
    print("\nPega estas dos líneas en dl-auditoria-config.php del servidor (sustituyendo las anteriores):\n")
    print(f"    'admin_password_hash' => '{valores['admin_password_hash']}',")
    print(f"    'panel_sal' => '{valores['panel_sal']}',")
    print("\nNo guardes esta salida en ningún archivo del proyecto ni la pegues en un chat.")
    print("Más cómodo y sin copiar nada: --subir (ver --help).")


if __name__ == "__main__":
    main()
