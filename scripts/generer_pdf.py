#!/usr/bin/env python3
# ============================================================
# scripts/generer_pdf.py
# Usage: python3 generer_pdf.py <json_data_file> <type> <output>
# type: programmatique | suivi
# ============================================================
import sys, json, os, io, hashlib
from datetime import datetime

from reportlab.lib.pagesizes import A4
from reportlab.lib import colors
from reportlab.lib.units import cm, mm
from reportlab.platypus import (SimpleDocTemplate, Table, TableStyle,
    Paragraph, Spacer, Image, HRFlowable)
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_CENTER, TA_LEFT, TA_RIGHT
import qrcode

# ── Arguments ────────────────────────────────────────────────
data_file = sys.argv[1]   # fichier JSON temporaire
doc_type  = sys.argv[2]
output    = sys.argv[3]

with open(data_file, 'r', encoding='utf-8') as f:
    data = json.load(f)

ens          = data['enseignant']
fiches_data  = data['fiches']
annee        = data.get('annee', '2024-2025')
verify_url   = data.get('verify_url', '')
gen_date     = datetime.now().strftime('%d/%m/%Y à %H:%M')

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
logo_path = os.path.join(BASE, 'logo_ujkz.jpg')

# ── Couleurs ─────────────────────────────────────────────────
VERT      = colors.HexColor('#006837')
OR        = colors.HexColor('#FFB300')
GRIS_H    = colors.HexColor('#D0D0D0')
GRIS_SEM  = colors.HexColor('#E8E8E8')
GRIS_TOT  = colors.HexColor('#C0C0C0')
GRIS_GRD  = colors.HexColor('#A0A0A0')
NOIR      = colors.black
BLANC     = colors.white

# ── Styles texte ─────────────────────────────────────────────
def S(name, **kw):
    s = ParagraphStyle(name, fontName='Helvetica', fontSize=8,
                       leading=10, spaceAfter=0, spaceBefore=0)
    for k, v in kw.items(): setattr(s, k, v)
    return s

sN   = S('N')
sC   = S('C', alignment=TA_CENTER)
sR   = S('R', alignment=TA_RIGHT)
sB   = S('B', fontName='Helvetica-Bold')
sBC  = S('BC', fontName='Helvetica-Bold', alignment=TA_CENTER)
sBI  = S('BI', fontName='Helvetica-BoldOblique', alignment=TA_CENTER)
sI   = S('I', fontName='Helvetica-Oblique', alignment=TA_CENTER)
sHdr = S('H', fontName='Helvetica-Bold', fontSize=7.5, alignment=TA_CENTER, leading=9)
sHdrSm = S('HS', fontName='Helvetica-Bold', fontSize=6.5, alignment=TA_CENTER, leading=8)
sSm  = S('SM', fontSize=7, leading=9)
sSmC = S('SMC', fontSize=7, leading=9, alignment=TA_CENTER)
sMini= S('MI', fontSize=6.5, fontName='Helvetica-Oblique',
         textColor=colors.HexColor('#555555'))
sMiniC = S('MIC', fontSize=6.5, fontName='Helvetica-Oblique',
           textColor=colors.HexColor('#555555'), alignment=TA_CENTER)
sValOK = S('VOK', fontSize=8, fontName='Helvetica-Bold',
           alignment=TA_CENTER, textColor=colors.HexColor('#006837'))
sValKO = S('VKO', fontSize=8, fontName='Helvetica-Bold',
           alignment=TA_CENTER, textColor=colors.HexColor('#C62828'))
sValAt = S('VAT', fontSize=8, fontName='Helvetica-Oblique',
           alignment=TA_CENTER, textColor=colors.HexColor('#888888'))

# ── Helpers ───────────────────────────────────────────────────
def abrev(n): return {'Licence 1':'L1','Licence 2':'L2','Licence 3':'L3',
                      'Master 1':'M1','Master 2':'M2'}.get(n.strip(), n)

def parc(f):
    a = abrev(f.get('niveau',''))
    s = f.get('semestre','')
    p = f.get('parcours','') or ''
    return (a + s + ' — ' + p) if p else (a + s)

def p(t, st=None): return Paragraph(str(t), st or sN)
def pc(t, st=None): return Paragraph(str(t), st or sC)
def pb(t): return Paragraph(f'<b>{t}</b>', sBC)

def val_cell(role_key, vals, label):
    v = vals.get(role_key, {})
    dec = v.get('decision', '')
    nom = v.get('nom', '')
    date = v.get('date', '')
    lines = [Paragraph(f'<b>{label}</b>', sBC)]
    if dec == 'valide':
        lines.append(Paragraph(f'<b>✓ {nom}</b>', sValOK))
        lines.append(Paragraph(date, sSmC))
    elif dec == 'rejete':
        lines.append(Paragraph('✕ Rejeté', sValKO))
        lines.append(Paragraph(date, sSmC))
    else:
        lines.append(Paragraph('En attente', sValAt))
    return lines

# ── QR Code ──────────────────────────────────────────────────
def make_qr(url_or_text):
    qr = qrcode.QRCode(version=None, error_correction=qrcode.constants.ERROR_CORRECT_M,
                       box_size=3, border=2)
    qr.add_data(url_or_text)
    qr.make(fit=True)
    img_pil = qr.make_image(fill_color='black', back_color='white')
    buf = io.BytesIO()
    img_pil.save(buf, format='PNG')
    buf.seek(0)
    return Image(buf, width=2.2*cm, height=2.2*cm)

# ── Construction PDF ──────────────────────────────────────────
W_PAGE = A4[0]
H_PAGE = A4[1]
ML = 1.5*cm; MR = 1.5*cm; MT = 1.2*cm; MB = 1.5*cm
W  = W_PAGE - ML - MR

doc = SimpleDocTemplate(output, pagesize=A4,
    leftMargin=ML, rightMargin=MR, topMargin=MT, bottomMargin=MB)
story = []

type_label = 'vacataire' if ens.get('type_enseignant') == 'vacataire' else 'permanent'
validations = ens.get('validations', {})

# ── EN-TÊTE ──────────────────────────────────────────────────
left_lines = [
    '<b>MINISTERE DE L\'ENSEIGNEMENT</b>',
    '<b>SUPERIEUR, DE LA RECHERCHE</b>',
    '<b>ET DE L\'INNOVATION</b>',
    '<b>— — —</b>',
    '<b>SECRETARIAT GENERAL</b>',
    '<b>— — —</b>',
    '<b>UNIVERSITE JOSEPH KI-ZERBO</b>',
    '<b>— — —</b>',
    '<b>PRESIDENCE</b>',
    '<b>— — — —</b>',
]

sLeft = S('L7', fontSize=7.5, leading=9.5, alignment=TA_CENTER)
sRight = S('R7', fontSize=7.5, leading=10, alignment=TA_RIGHT)

logo_img = Image(logo_path, width=2.5*cm, height=2.5*cm) if os.path.exists(logo_path) else Spacer(2.5*cm, 2.5*cm)

header_data = [[
    [Paragraph(l, sLeft) for l in left_lines],
    logo_img,
    [
        Paragraph('<b><i>BURKINA FASO</i></b>', sRight),
        Paragraph('<i>La Patrie ou la mort, Nous vaincrons</i>', sRight),
        Spacer(1, 4*mm),
        Paragraph('.' * 32, sRight),
        Spacer(1, 2*mm),
        Paragraph(f'Année universitaire {annee}', sRight),
    ],
]]

ht = Table(header_data, colWidths=[W*0.38, W*0.24, W*0.38])
ht.setStyle(TableStyle([
    ('VALIGN',        (0,0), (-1,-1), 'MIDDLE'),
    ('TOPPADDING',    (0,0), (-1,-1), 2),
    ('BOTTOMPADDING', (0,0), (-1,-1), 2),
    ('LEFTPADDING',   (0,0), (-1,-1), 2),
    ('RIGHTPADDING',  (0,0), (-1,-1), 2),
]))
story.append(ht)
story.append(Spacer(1, 3*mm))

# ── TITRE ────────────────────────────────────────────────────
if doc_type == 'programmatique':
    titre = 'FICHE PROGRAMMATIQUE'
    tbl_title = 'Tableau descriptif des enseignements confiés en réunion de département'
else:
    titre = 'FICHE SEMESTRIELLE DE SUIVI DES HEURES EFFECTUÉES'
    tbl_title = 'Tableau descriptif des enseignements confiés et effectués'

title_tbl = Table([[Paragraph(f'<u><b>{titre}</b></u>',
    S('TT', fontSize=13, fontName='Helvetica-Bold', alignment=TA_CENTER, leading=16))]],
    colWidths=[W])
title_tbl.setStyle(TableStyle([
    ('BACKGROUND',    (0,0), (-1,-1), colors.HexColor('#E8E8E8')),
    ('TOPPADDING',    (0,0), (-1,-1), 5),
    ('BOTTOMPADDING', (0,0), (-1,-1), 5),
    ('BOX',           (0,0), (-1,-1), 0.5, NOIR),
]))
story.append(title_tbl)
story.append(Paragraph(f'Pour enseignant <i>{type_label}</i>',
    S('ST', fontSize=9, fontName='Helvetica-Oblique', alignment=TA_CENTER)))
story.append(Spacer(1, 3*mm))

# ── INFOS ENSEIGNANT ─────────────────────────────────────────
nom      = ens.get('nom','')
grade    = ens.get('grade','')
date_nom = ens.get('date_nomination','') or '..........'
vs       = str(ens.get('volume_statutaire','') or '') + 'h' if ens.get('volume_statutaire') else '..........'
ab       = str(ens.get('abattement','') or '') + 'h' if ens.get('abattement') else '..........'
motif    = ens.get('motif_abattement','') or '..........'
va       = str(ens.get('volume_apres_abatt','') or '') + 'h' if ens.get('volume_apres_abatt') else '..........'
er       = ens.get('etab_rattachement','') or '..........'
eb       = ens.get('etab_beneficiaire','') or '..........'

sInfo = S('INF', fontSize=8.5, leading=12)
def iline(parts):
    txt = '   '.join(f'<b>{k}</b> {v}' for k, v in parts)
    return Paragraph(txt, sInfo)

story += [
    iline([('Nom :', nom)]),
    iline([('Grade :', grade), ('    Date de Nomination :', date_nom)]),
    iline([('Volume horaire statutaire :', vs), ('    Abattement :', ab),
           ('    Motif de l\'abattement :', motif)]),
    iline([('Volume horaire obligatoire après abattement :', va)]),
]
if er and er != '..........':
    story.append(iline([('Établissement de rattachement administratif :', er)]))
story.append(iline([('Établissement bénéficiaire des enseignements :', eb)]))
story.append(Spacer(1, 4*mm))
story.append(Paragraph(f'<b><u>{tbl_title}</u></b>',
    S('TBL', fontSize=8.5, fontName='Helvetica-Bold', alignment=TA_CENTER)))
story.append(Spacer(1, 2*mm))

# ── TABLEAU ───────────────────────────────────────────────────
s1 = [f for f in fiches_data if f.get('semestre') == 'S1']
s2 = [f for f in fiches_data if f.get('semestre') == 'S2']
tcm1 = sum(int(f.get('volume_cm',0) or 0) for f in s1)
ttd1 = sum(int(f.get('volume_td',0) or 0) for f in s1)
tcm2 = sum(int(f.get('volume_cm',0) or 0) for f in s2)
ttd2 = sum(int(f.get('volume_td',0) or 0) for f in s2)

def eff(fiches_list):
    cm = sum(sum(int(j.get('volume_cm_effectue',0) or 0) for j in f.get('justificatifs',[])) for f in fiches_list)
    td = sum(sum(int(j.get('volume_td_effectue',0) or 0) for j in f.get('justificatifs',[])) for f in fiches_list)
    return cm, td

ecm1,etd1 = eff(s1)
ecm2,etd2 = eff(s2)

if doc_type == 'programmatique':
    cw = [W*0.04, W*0.10, W*0.17, W*0.32, W*0.06, W*0.105, W*0.085, W*0.08]
    # Entête simple
    hdr = [[pb('N°'), pb('CODE'), pb('PARCOURS'), pb('UE ou ECUE'),
            pb('NTC'), pb('CT (h)'), pb('TD (h)'), pb('TP')]]

    def frow(i, f):
        cm = int(f.get('volume_cm',0) or 0)
        td = int(f.get('volume_td',0) or 0)
        return [pc(i), pc(f.get('code','') or ''),
                p(parc(f), sSm), p(f.get('cours','') or ''),
                pc(f.get('ntc','') or ''),
                pc(str(cm) if cm else ''), pc(str(td) if td else ''), pc('')]

    def sem_row(lbl):
        return [[Paragraph(f'<b><i>{lbl}</i></b>', sBI)] + [pc('')]*7]

    def tot_row(lbl, n, cm, td, grand=False):
        return [[pb(lbl), pc(''), pc(''), pc(''),
                 pb(str(n) if n else ''),
                 pb(str(cm) if cm else ''), pb(str(td) if td else ''), pc('')]]

    rows = hdr
    rows += sem_row("Premier semestre de l'année")
    rows += [frow(i+1, f) for i, f in enumerate(s1)]
    rows += tot_row('TOTAL DU SEMESTRE 1', len(s1), tcm1, ttd1)
    rows += sem_row("Deuxième semestre de l'année")
    rows += [frow(i+1, f) for i, f in enumerate(s2)]
    rows += tot_row('TOTAL DU SEMESTRE 2', len(s2), tcm2, ttd2)
    rows += tot_row('TOTAUX', len(fiches_data), tcm1+tcm2, ttd1+ttd2, True)

    ts = [
        ('GRID',        (0,0), (-1,-1), 0.4, NOIR),
        ('BACKGROUND',  (0,0), (-1,0),  GRIS_H),
        ('FONTNAME',    (0,0), (-1,-1), 'Helvetica'),
        ('FONTSIZE',    (0,0), (-1,-1), 8),
        ('VALIGN',      (0,0), (-1,-1), 'MIDDLE'),
        ('TOPPADDING',  (0,0), (-1,-1), 2),
        ('BOTTOMPADDING',(0,0),(-1,-1), 2),
        ('LEFTPADDING', (0,0), (-1,-1), 2),
        ('RIGHTPADDING',(0,0), (-1,-1), 2),
        # Ligne semestre S1
        ('BACKGROUND',  (0,1), (-1,1), GRIS_SEM),
        ('SPAN',        (0,1), (-1,1)),
        # Total S1
        ('BACKGROUND',  (0, 1+len(s1)+1), (-1, 1+len(s1)+1), GRIS_H),
        ('SPAN',        (0, 1+len(s1)+1), (3, 1+len(s1)+1)),
        # Ligne semestre S2
        ('BACKGROUND',  (0, 1+len(s1)+2), (-1, 1+len(s1)+2), GRIS_SEM),
        ('SPAN',        (0, 1+len(s1)+2), (-1, 1+len(s1)+2)),
        # Total S2
        ('BACKGROUND',  (0, 1+len(s1)+2+len(s2)+1), (-1, 1+len(s1)+2+len(s2)+1), GRIS_H),
        ('SPAN',        (0, 1+len(s1)+2+len(s2)+1), (3, 1+len(s1)+2+len(s2)+1)),
        # TOTAUX
        ('BACKGROUND',  (0,-1), (-1,-1), GRIS_GRD),
        ('FONTNAME',    (0,-1), (-1,-1), 'Helvetica-Bold'),
        ('SPAN',        (0,-1), (3,-1)),
    ]

else:  # suivi
    cw = [W*0.035, W*0.085, W*0.14, W*0.25, W*0.05,
          W*0.075, W*0.065, W*0.065, W*0.075, W*0.065, W*0.065]

    # En-tête double ligne
    hdr1 = [[pb('N°'), pb('CODE'), pb('PARCOURS'), pb('UE ou ECUE'), pb('NTC'),
             pb('Volume horaire total confié'), pc(''), pc(''),
             pb('Volume horaire effectué'), pc(''), pc('')]]
    hdr2 = [[pc('')]*5 + [pb('CT'), pb('TD'), pb('TP'), pb('CT'), pb('TD'), pb('TP')]]

    def frow(i, f):
        cm = int(f.get('volume_cm',0) or 0)
        td = int(f.get('volume_td',0) or 0)
        js = f.get('justificatifs',[])
        ecm = sum(int(j.get('volume_cm_effectue',0) or 0) for j in js)
        etd = sum(int(j.get('volume_td_effectue',0) or 0) for j in js)
        return [pc(i), pc(f.get('code','') or ''),
                p(parc(f), sSm), p(f.get('cours','') or ''),
                pc(f.get('ntc','') or ''),
                pc(str(cm) if cm else ''), pc(str(td) if td else ''), pc(''),
                pc(str(ecm) if ecm else ''), pc(str(etd) if etd else ''), pc('')]

    def sem_row(lbl):
        return [[Paragraph(f'<b><i>{lbl}</i></b>', sBI)] + [pc('')]*10]

    def tot_row(lbl, n, cm, td, ecm, etd, grand=False):
        return [[pb(lbl), pc(''), pc(''), pc(''), pb(str(n) if n else ''),
                 pb(str(cm) if cm else ''), pb(str(td) if td else ''), pc(''),
                 pb(str(ecm) if ecm else ''), pb(str(etd) if etd else ''), pc('')]]

    rows = hdr1 + hdr2
    rows += sem_row("Premier semestre de l'année")
    rows += [frow(i+1, f) for i, f in enumerate(s1)]
    rows += tot_row('TOTAL DU SEMESTRE 1', len(s1), tcm1, ttd1, ecm1, etd1)
    rows += sem_row("Deuxième semestre de l'année")
    rows += [frow(i+1, f) for i, f in enumerate(s2)]
    rows += tot_row('TOTAL DU SEMESTRE 2', len(s2), tcm2, ttd2, ecm2, etd2)
    rows += tot_row('TOTAUX', len(fiches_data), tcm1+tcm2, ttd1+ttd2, ecm1+ecm2, etd1+etd2, True)

    off = 2  # offset lignes header
    ts = [
        ('GRID',         (0,0), (-1,-1), 0.4, NOIR),
        ('BACKGROUND',   (0,0), (-1,1),  GRIS_H),
        ('FONTNAME',     (0,0), (-1,-1), 'Helvetica'),
        ('FONTSIZE',     (0,0), (-1,-1), 7.5),
        ('VALIGN',       (0,0), (-1,-1), 'MIDDLE'),
        ('TOPPADDING',   (0,0), (-1,-1), 2),
        ('BOTTOMPADDING',(0,0), (-1,-1), 2),
        ('LEFTPADDING',  (0,0), (-1,-1), 2),
        ('RIGHTPADDING', (0,0), (-1,-1), 2),
        # Fusions ligne 1 header
        ('SPAN', (0,0), (0,1)),   # N°
        ('SPAN', (1,0), (1,1)),   # CODE
        ('SPAN', (2,0), (2,1)),   # PARCOURS
        ('SPAN', (3,0), (3,1)),   # UE
        ('SPAN', (4,0), (4,1)),   # NTC
        ('SPAN', (5,0), (7,0)),   # Vol confié
        ('SPAN', (8,0), (10,0)),  # Vol effectué
        # Semestres et totaux
        ('BACKGROUND', (0,off), (-1,off), GRIS_SEM),
        ('SPAN',       (0,off), (-1,off)),
        ('BACKGROUND', (0,off+len(s1)+1), (-1,off+len(s1)+1), GRIS_H),
        ('SPAN',       (0,off+len(s1)+1), (3, off+len(s1)+1)),
        ('BACKGROUND', (0,off+len(s1)+2), (-1,off+len(s1)+2), GRIS_SEM),
        ('SPAN',       (0,off+len(s1)+2), (-1,off+len(s1)+2)),
        ('BACKGROUND', (0,off+len(s1)+len(s2)+3), (-1,off+len(s1)+len(s2)+3), GRIS_H),
        ('SPAN',       (0,off+len(s1)+len(s2)+3), (3, off+len(s1)+len(s2)+3)),
        ('BACKGROUND', (0,-1), (-1,-1), GRIS_GRD),
        ('FONTNAME',   (0,-1), (-1,-1), 'Helvetica-Bold'),
        ('SPAN',       (0,-1), (3,-1)),
    ]

tbl = Table(rows, colWidths=cw, repeatRows=1 if doc_type == 'programmatique' else 2)
tbl.setStyle(TableStyle(ts))
story.append(tbl)
story.append(Spacer(1, 5*mm))

# ── DATE + SIGNATURES + QR CODE ──────────────────────────────
story.append(Paragraph(
    f'<b>Ouagadougou, le {gen_date}</b>',
    S('DATE', fontSize=9, fontName='Helvetica-Bold', alignment=TA_RIGHT)
))
story.append(Spacer(1, 3*mm))
story.append(Paragraph('<b>Vu et approuvé par</b>',
    S('VU', fontSize=9, fontName='Helvetica-Bold')))
story.append(Spacer(1, 3*mm))

# QR Code
qr_text = verify_url if verify_url else (
    f'UJKZ-FICHE|{ens.get("matricule","?")}'
    f'|{ens.get("nom","?")}|{annee}|{gen_date}'
    f'|{hashlib.sha256((ens.get("matricule","") + annee + gen_date).encode()).hexdigest()[:12].upper()}'
)
qr_img = make_qr(qr_text)

# Ligne 1 : Chef Département | La DEI | QR
sig1_data = [[
    val_cell('chef_dept', validations, 'Le Chef de Département'),
    val_cell('dei', validations, 'La DEI'),
    [Paragraph('<b>Code de vérification</b>', sBC),
     Spacer(1, 1*mm), qr_img,
     Paragraph(qr_text[:40] + ('…' if len(qr_text) > 40 else ''), sMiniC)],
]]
sig1 = Table(sig1_data, colWidths=[W*0.38, W*0.38, W*0.24])
sig1.setStyle(TableStyle([
    ('VALIGN',       (0,0), (-1,-1), 'TOP'),
    ('ALIGN',        (2,0), (2,0),   'CENTER'),
    ('TOPPADDING',   (0,0), (-1,-1), 3),
    ('BOTTOMPADDING',(0,0), (-1,-1), 3),
    ('LEFTPADDING',  (0,0), (-1,-1), 4),
    ('RIGHTPADDING', (0,0), (-1,-1), 4),
    ('BOX',          (2,0), (2,0), 0.5, colors.HexColor('#CCCCCC')),
]))
story.append(sig1)
story.append(Spacer(1, 4*mm))

# Ligne 2 : Directeur Adjoint | Directeur
sig2_data = [[
    val_cell('directeur_adjoint', validations, 'Le Directeur Adjoint'),
    val_cell('directeur', validations, 'Le Directeur'),
]]
sig2 = Table(sig2_data, colWidths=[W*0.5, W*0.5])
sig2.setStyle(TableStyle([
    ('VALIGN',       (0,0), (-1,-1), 'TOP'),
    ('TOPPADDING',   (0,0), (-1,-1), 3),
    ('BOTTOMPADDING',(0,0), (-1,-1), 3),
    ('LEFTPADDING',  (0,0), (-1,-1), 4),
    ('RIGHTPADDING', (0,0), (-1,-1), 4),
]))
story.append(sig2)

# ── PIED DE PAGE ─────────────────────────────────────────────
story.append(Spacer(1, 3*mm))
story.append(HRFlowable(width=W, thickness=0.5, color=colors.HexColor('#AAAAAA')))
story.append(Spacer(1, 1.5*mm))

if doc_type == 'programmatique':
    notes = ('¹ Établir une fiche par établissement (CUP, UFR ou Institut) où intervient l\'enseignant. '
             '² Calculer sans convertir les TD et TP en heures de cours théoriques. '
             'NB : NTC = nombre total de crédits. Ne remplir qu\'une seule fiche pour toutes les interventions.')
else:
    notes = ('¹ Cocher le semestre d\'activité. '
             '² Établir une fiche de suivi par établissement. '
             '³ Calculer sans convertir les TD et TP en heures de cours théoriques. '
             'NTC = nombre total de crédits.')
story.append(Paragraph(notes, sMini))
story.append(Spacer(1, 1.5*mm))
story.append(Paragraph(
    f'Généré par le Système de gestion des fiches programmatiques de l\'UJKZ — {gen_date}',
    sMiniC
))

doc.build(story)
print(f'OK:{output}')
