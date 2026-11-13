import imageio.v2 as imageio
from PIL import Image
import os, numpy as np

OUT="/Users/rosliamir/selgem-srs/compare"
order=["login","dashboard","account","attendance","monitoring","map","site-management","user-management"]
paths=[os.path.join(OUT,f"compare-{n}.png") for n in order]

# target canvas (even dims required by h264)
TW=1280
imgs=[Image.open(p).convert("RGB") for p in paths]
TH=max(int(im.size[1]*TW/im.size[0]) for im in imgs)
TH+= TH%2; TW+= TW%2

def frame(im):
    w=TW; h=int(im.size[1]*w/im.size[0])
    r=im.resize((w,h))
    c=Image.new("RGB",(TW,TH),(15,23,42))
    c.paste(r,(0,0))
    return np.asarray(c)

FPS=30; HOLD=int(2.6*FPS); FADE=int(0.4*FPS)
writer=imageio.get_writer(os.path.join(OUT,"comparison.mp4"),fps=FPS,codec="libx264",
                          quality=8,macro_block_size=None,pixelformat="yuv420p")
prev=None
for im in imgs:
    f=frame(im)
    if prev is not None:
        for k in range(FADE):
            a=k/FADE
            writer.append_data((prev*(1-a)+f*a).astype('uint8'))
    for _ in range(HOLD):
        writer.append_data(f)
    prev=f
writer.close()
sz=os.path.getsize(os.path.join(OUT,"comparison.mp4"))
print(f"wrote comparison.mp4 ({sz//1024} KB), {TW}x{TH}, {len(imgs)} screens")
