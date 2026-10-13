"""Render a small text/vector social card; no patient or operational information."""
from pathlib import Path
from PIL import Image,ImageDraw,ImageFont
public=Path(__file__).resolve().parents[1]/'public'
canvas=Image.new('RGB',(1200,630),'#101b2b');draw=ImageDraw.Draw(canvas)
def font(size,bold=False):
    base=Path('C:/Windows/Fonts')
    return ImageFont.truetype(str(base/('arialbd.ttf' if bold else 'arial.ttf')),size)
draw.rounded_rectangle((66,64,1134,566),radius=28,outline='#315568',width=2)
draw.rectangle((68,124,75,510),fill='#5eead4')
draw.text((112,105),'ASR HEALTH',font=font(26,True),fill='#5eead4')
draw.text((106,164),'MAPA',font=font(116,True),fill='#ffffff')
draw.text((114,325),'Ambulance operations',font=font(42,True),fill='#edf5ff')
draw.text((114,390),'Connected crews. Coordinated response.',font=font(30),fill='#b1c3d7')
draw.text((114,490),'ASR HEALTH COMMAND CENTRE',font=font(19,True),fill='#5eead4')
draw.rounded_rectangle((890,168,1050,285),radius=24,fill='#173d40')
draw.rectangle((955,191,983,261),fill='#5eead4');draw.rectangle((934,212,1004,240),fill='#5eead4')
target=public/'images/mapa-social-preview.png';canvas.save(target,optimize=True)
print(f'Created {target.name}: 1200 x 630, {target.stat().st_size} bytes.')
