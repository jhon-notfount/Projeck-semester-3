from pathlib import Path
import re
from docx import Document
from docx.shared import Inches, Pt, Cm, RGBColor
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.opc.constants import RELATIONSHIP_TYPE as RT

ROOT = Path(__file__).resolve().parent
source = (ROOT / 'bahan_presentasi_database_hydrotech.md').read_text(encoding='utf-8')
doc = Document()
section = doc.sections[0]
section.page_width, section.page_height = Cm(21), Cm(29.7)
section.top_margin = section.bottom_margin = Cm(2)
section.left_margin = section.right_margin = Cm(2.2)
section.header_distance = section.footer_distance = Cm(1)
section.different_first_page_header_footer = True
styles = doc.styles
for name in ['Normal', 'Body Text', 'List Bullet', 'List Number']:
    styles[name].font.name = 'Calibri'
    styles[name].font.size = Pt(11)
    styles[name].paragraph_format.space_after = Pt(6)
    styles[name].paragraph_format.line_spacing = 1.12
for name, size in [('Title', 30), ('Heading 1', 19), ('Heading 2', 14), ('Heading 3', 12)]:
    styles[name].font.name = 'Calibri'
    styles[name].font.size = Pt(size)
    styles[name].font.color.rgb = RGBColor.from_string('155E63')
    styles[name].paragraph_format.keep_with_next = True
styles['Heading 1'].paragraph_format.page_break_before = True
styles['Heading 1'].paragraph_format.space_after = Pt(12)
code_style = styles.add_style('Kode', 1)
code_style.font.name = 'Consolas'
code_style.font.size = Pt(8.5)
code_style.paragraph_format.space_after = Pt(0)
code_style.paragraph_format.line_spacing = 1
code_style.paragraph_format.left_indent = Cm(.25)
code_style.paragraph_format.right_indent = Cm(.15)

def shade(element, fill):
    shd = OxmlElement('w:shd')
    shd.set(qn('w:fill'), fill)
    element.append(shd)

def link(p, text, url=None, anchor=None):
    h = OxmlElement('w:hyperlink')
    if url:
        h.set(qn('r:id'), p.part.relate_to(url, RT.HYPERLINK, is_external=True))
    if anchor:
        h.set(qn('w:anchor'), anchor)
    r = OxmlElement('w:r')
    props = OxmlElement('w:rPr')
    color = OxmlElement('w:color'); color.set(qn('w:val'), '155E63'); props.append(color)
    r.append(props)
    t = OxmlElement('w:t'); t.text = text; r.append(t)
    h.append(r); p._p.append(h)

def inline(p, text):
    pattern = r'(\*\*.*?\*\*|`[^`]+`|\[[^\]]+\]\([^)]+\))'
    for part in re.split(pattern, text):
        if part.startswith('**') and part.endswith('**'):
            p.add_run(part[2:-2]).bold = True
        elif part.startswith('`') and part.endswith('`'):
            run = p.add_run(part[1:-1]); run.font.name = 'Consolas'; run.font.size = Pt(9)
        elif re.match(r'\[[^\]]+\]\([^)]+\)', part):
            m = re.match(r'\[([^\]]+)\]\(([^)]+)\)', part)
            link(p, m[1], url=m[2])
        else:
            p.add_run(part)

header = section.header.paragraphs[0]
header.text = 'HYDROTECH  |  Panduan Presentasi Database'
header.runs[0].font.size = Pt(9)
header.runs[0].font.color.rgb = RGBColor.from_string('667580')
footer = section.footer.paragraphs[0]
footer.alignment = WD_ALIGN_PARAGRAPH.RIGHT
footer.add_run('Hydrotech  •  ')
field = OxmlElement('w:fldSimple'); field.set(qn('w:instr'), 'PAGE'); footer._p.append(field)
for run in footer.runs:
    run.font.size = Pt(9)

doc.add_paragraph('\n\n')
p = doc.add_paragraph('HYDROTECH', 'Title')
p = doc.add_paragraph('Penjelasan Database\ndan Integrasi Web')
p.runs[0].font.size = Pt(24)
p.runs[0].bold = True
p = doc.add_paragraph('Bahan Presentasi dan Persiapan Tanya Jawab')
p.runs[0].font.size = Pt(15)
p.runs[0].font.color.rgb = RGBColor.from_string('667580')
doc.add_paragraph('')
p = doc.add_paragraph('8 TABEL   •   57 KOLOM   •   7 RELASI')
p.runs[0].bold = True
p.runs[0].font.color.rgb = RGBColor.from_string('155E63')
doc.add_paragraph('PHP • MySQL • PDO • Laragon')
doc.add_paragraph('\nDokumen ini membahas struktur database, alur komunikasi web, fungsi setiap tabel, relasi, pengelolaan data, serta batasan implementasi yang perlu dipahami saat presentasi.')
doc.add_paragraph('\n22 September 2026')
doc.add_page_break()
p = doc.add_paragraph('Daftar Isi', 'Title')
doc.add_paragraph('Klik judul bagian untuk menuju pembahasan yang diinginkan.')
headings = re.findall(r'^# (.+)$', source, re.M)
for idx, title in enumerate(headings):
    p = doc.add_paragraph()
    p.paragraph_format.space_after = Pt(8)
    link(p, title, anchor=f'chapter_{idx}')

lines = source.splitlines()
i = 0
chapter = 0
while i < len(lines):
    line = lines[i]
    if not line.strip():
        i += 1; continue
    if line.startswith('```'):
        i += 1
        block = []
        while i < len(lines) and not lines[i].startswith('```'):
            block.append(lines[i]); i += 1
        for j, code in enumerate(block):
            p = doc.add_paragraph(code, 'Kode')
            shade(p._p.get_or_add_pPr(), 'F0F4F5')
            p.paragraph_format.keep_with_next = j < len(block)-1
        doc.add_paragraph().paragraph_format.space_after = Pt(2)
        i += 1; continue
    if line.startswith('|'):
        rows = []
        while i < len(lines) and lines[i].startswith('|'):
            cells = [v.strip() for v in lines[i].strip().strip('|').split('|')]
            if not all(re.fullmatch(r':?-+:?', v) for v in cells):
                rows.append(cells)
            i += 1
        table = doc.add_table(rows=0, cols=len(rows[0]))
        table.style = 'Table Grid'
        for row_idx, cells in enumerate(rows):
            row = table.add_row()
            trpr = row._tr.get_or_add_trPr()
            trpr.append(OxmlElement('w:cantSplit'))
            if row_idx == 0:
                trpr.append(OxmlElement('w:tblHeader'))
            for cell, value in zip(row.cells, cells):
                inline(cell.paragraphs[0], value)
                shade(cell._tc.get_or_add_tcPr(), '155E63' if row_idx == 0 else ('F0F5F5' if row_idx % 2 else 'FFFFFF'))
                for p in cell.paragraphs:
                    p.paragraph_format.space_after = Pt(4)
                    p.paragraph_format.space_before = Pt(4)
                    p.paragraph_format.line_spacing = 1.05
                    for run in p.runs:
                        run.font.size = Pt(9)
                        if row_idx == 0:
                            run.bold = True
                            run.font.color.rgb = RGBColor(255,255,255)
        doc.add_paragraph().paragraph_format.space_after = Pt(2)
        continue
    match = re.match(r'^(#{1,3}) (.+)$', line)
    if match:
        level = len(match[1])
        p = doc.add_paragraph(match[2], f'Heading {level}')
        if level == 1:
            start = OxmlElement('w:bookmarkStart'); start.set(qn('w:id'), str(chapter)); start.set(qn('w:name'), f'chapter_{chapter}')
            end = OxmlElement('w:bookmarkEnd'); end.set(qn('w:id'), str(chapter))
            p._p.insert(0, start); p._p.append(end); chapter += 1
    elif line.startswith('> '):
        p = doc.add_paragraph()
        inline(p, line[2:])
        p.paragraph_format.left_indent = Cm(.4)
        p.paragraph_format.right_indent = Cm(.25)
        p.paragraph_format.space_before = Pt(5)
        p.paragraph_format.space_after = Pt(8)
        shade(p._p.get_or_add_pPr(), 'EAF3F2')
        for run in p.runs: run.italic = True
    elif line.startswith('- '):
        inline(doc.add_paragraph(style='List Bullet'), line[2:])
    else:
        p = doc.add_paragraph()
        inline(p, line)
    i += 1

doc.core_properties.title = 'Penjelasan Database dan Integrasi Web Hydrotech'
doc.core_properties.subject = 'Bahan presentasi database, relasi, dashboard, dan Laragon'
doc.core_properties.author = 'Hydrotech'
doc.core_properties.language = 'id-ID'
target = ROOT / 'Panduan_Presentasi_Database_Hydrotech.docx'
doc.save(target)
print(target)
print(f'{len(doc.paragraphs)} paragraf; {len(doc.tables)} tabel; {chapter} bagian utama')
