# -*- coding: utf-8 -*-
"""Genera tests/Fixtures/valle-verde.dxf: una lotificacion de prueba.

No es un plano decorativo. Cada rasgo esta puesto para ejercitar una
trampa concreta del formato DXF que rompe a los parsers ingenuos:

  - TEXT con justificacion centrada (72=1, 73=2): la posicion real va en
    11/21, y el 10/20 esta DESPLAZADO a proposito. Un parser que lea
    10/20 ubica mal todas las etiquetas.
  - Un lote en el formato viejo POLYLINE/VERTEX/SEQEND, que es el que
    exporta buena parte del software de topografia.
  - Un MTEXT con codigos de formato en linea (\\H, \\C).
  - Seis lotes en abanico alrededor de una cul-de-sac, con arcos (bulge)
    en los lados curvos; el 42 solo aparece en los vertices donde no es
    cero, que es como lo escribe AutoCAD.
  - Lotes trapezoidales contra un lindero diagonal.

Para regenerarlo:  python3 tests/Fixtures/valle-verde.py
"""
import math
import os

t = []
def add(c, v): t.append((c, v))

def cabecera(insunits=6):
    add(0, "SECTION"); add(2, "HEADER")
    add(9, "$ACADVER"); add(1, "AC1015")
    add(9, "$INSUNITS"); add(70, insunits)
    add(0, "ENDSEC")

def lwpoly(capa, puntos, bulges=None):
    add(0, "LWPOLYLINE"); add(100, "AcDbEntity"); add(8, capa)
    add(100, "AcDbPolyline"); add(90, len(puntos)); add(70, 1)
    for i, (x, y) in enumerate(puntos):
        add(10, f"{x:.6f}"); add(20, f"{y:.6f}")
        b = (bulges or {}).get(i)
        if b: add(42, f"{b:.10f}")

def polyline_vieja(capa, puntos):
    add(0, "POLYLINE"); add(100, "AcDbEntity"); add(8, capa)
    add(100, "AcDb2dPolyline"); add(66, 1)
    add(10, "0.0"); add(20, "0.0"); add(30, "0.0")
    add(70, 1)
    for x, y in puntos:
        add(0, "VERTEX"); add(8, capa); add(100, "AcDbVertex"); add(100, "AcDb2dVertex")
        add(10, f"{x:.6f}"); add(20, f"{y:.6f}"); add(30, "0.0"); add(70, 0)
    add(0, "SEQEND"); add(8, capa)

def texto(capa, x, y, s, h=2.0):
    add(0, "TEXT"); add(100, "AcDbEntity"); add(8, capa); add(100, "AcDbText")
    add(10, f"{x - 3.0:.6f}"); add(20, f"{y - 1.0:.6f}")
    add(40, f"{h:.4f}"); add(1, s); add(72, 1); add(73, 2)
    add(11, f"{x:.6f}"); add(21, f"{y:.6f}"); add(100, "AcDbText")

def mtexto(capa, x, y, s, h=2.0):
    add(0, "MTEXT"); add(100, "AcDbEntity"); add(8, capa); add(100, "AcDbMText")
    add(10, f"{x:.6f}"); add(20, f"{y:.6f}"); add(40, f"{h:.4f}")
    add(71, 5); add(1, s)

LOTES, CALLES, TEXTOS, VERDE, PERIM = "LOTES", "CALLES", "TEXTOS", "AREAS_VERDES", "PERIMETRO"

cabecera()
add(0, "SECTION"); add(2, "ENTITIES")

numero = 0

def manzana(x0, y0, frente, fondo, columnas, filas, sesgo=0.0, viejo_en=None):
    global numero
    for f in range(filas):
        for c in range(columnas):
            numero += 1
            xi = x0 + c * frente
            xd = xi + frente
            yb = y0 + f * fondo
            ya = yb + fondo
            ri = xd - sesgo * (ya - y0) if c == columnas - 1 else xd
            rd = xd - sesgo * (yb - y0) if c == columnas - 1 else xd
            pts = [(xi, yb), (rd, yb), (ri, ya), (xi, ya)]
            if viejo_en is not None and (f, c) == viejo_en:
                polyline_vieja(LOTES, pts)
            else:
                lwpoly(LOTES, pts)
            cx = sum(p[0] for p in pts) / 4
            cy = sum(p[1] for p in pts) / 4
            if numero == 1:
                mtexto(TEXTOS, cx, cy, r"\H1.2x;\C1;LOTE 1")
            else:
                texto(TEXTOS, cx, cy, str(numero))

def calle(x0, y0, x1, y1):
    lwpoly(CALLES, [(x0, y0), (x1, y0), (x1, y1), (x0, y1)])

manzana(10,  10, 10, 20, 12, 2, viejo_en=(0, 0))
calle(10, 50, 190, 58)
manzana(10,  58, 10, 20, 12, 2)
calle(10, 98, 190, 106)
manzana(10, 106, 10, 20, 12, 2, sesgo=0.25)
calle(130, 10, 138, 50)

cx, cy = 165.0, 30.0
r_int, r_ext = 12.0, 32.0
n = 6
paso = math.radians(180.0) / n
lwpoly(CALLES, [(cx + r_int*math.cos(2*math.pi*k/48), cy + r_int*math.sin(2*math.pi*k/48)) for k in range(48)])

for k in range(n):
    numero += 1
    a0, a1 = math.radians(-90) + k*paso, math.radians(-90) + (k+1)*paso
    p = [(cx + r_int*math.cos(a0), cy + r_int*math.sin(a0)),
         (cx + r_ext*math.cos(a0), cy + r_ext*math.sin(a0)),
         (cx + r_ext*math.cos(a1), cy + r_ext*math.sin(a1)),
         (cx + r_int*math.cos(a1), cy + r_int*math.sin(a1))]
    b = math.tan(paso / 4)
    lwpoly(LOTES, p, bulges={1: b, 3: -b})
    am, rm = (a0 + a1) / 2, (r_int + r_ext) / 2
    texto(TEXTOS, cx + rm*math.cos(am), cy + rm*math.sin(am), str(numero))

lwpoly(VERDE, [(10, 150), (60, 150), (60, 170), (10, 170)])
lwpoly(PERIM, [(5, 5), (205, 5), (205, 145), (140, 175), (5, 175)])

add(0, "ENDSEC"); add(0, "EOF")

destino = os.path.join(os.path.dirname(os.path.abspath(__file__)), "valle-verde.dxf")
with open(destino, "w", newline="\r\n") as fh:
    for c, v in t:
        fh.write(f"{c:>3}\n{v}\n")

area_wedge = (paso / 2) * (r_ext**2 - r_int**2)
print(f"{destino}")
print(f"  lotes: {numero}  ({numero-n} rectangulares/trapezoidales, {n} en abanico)")
print(f"  area analitica de cada lote en abanico: {area_wedge:.4f} m2")
