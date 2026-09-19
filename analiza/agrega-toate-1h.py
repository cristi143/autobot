"""1m CSV -> lumânări de 1h, un fișier pickle per simbol.
Streaming: minutele nu se țin niciodată în memorie. Doar biblioteca standard."""
import os, sys, time, pickle, gzip
from array import array

BAZA = "/home/cristi/Claude/01_Dunitru/historical_data"
IES  = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "historical_data", "_1h")
ORA  = 3_600_000

def agrega(simbol):
    d = os.path.join(BAZA, simbol, "candles_1m")
    fis = sorted(f for f in os.listdir(d) if f.endswith(".csv"))
    T=array('q'); O=array('d'); H=array('d'); L=array('d'); C=array('d')
    ora_c=None; o=h=l=c=0.0; ultim_t=-1
    for nume in fis:
        with open(os.path.join(d,nume),'rb') as f:
            brut=f.read()
        for ln in brut.split(b'\n')[1:]:
            if not ln: continue
            p=ln.split(b',')
            if len(p)<5: continue
            try:
                t=int(p[0])
            except ValueError:
                continue
            # fișierele zilnice se pot suprapune la margini; datele vin sortate,
            # deci un timp care nu avansează e duplicat
            if t<=ultim_t: continue
            ultim_t=t
            try:
                po=float(p[1]); ph=float(p[2]); pl=float(p[3]); pc=float(p[4])
            except ValueError:
                continue
            k=t-(t%ORA)
            if k!=ora_c:
                if ora_c is not None:
                    T.append(ora_c);O.append(o);H.append(h);L.append(l);C.append(c)
                ora_c=k; o=po; h=ph; l=pl; c=pc
            else:
                if ph>h: h=ph
                if pl<l: l=pl
                c=pc
    if ora_c is not None:
        T.append(ora_c);O.append(o);H.append(h);L.append(l);C.append(c)
    with gzip.open(os.path.join(IES,simbol+".pkl.gz"),'wb',compresslevel=1) as f:
        pickle.dump({'t':T,'o':O,'h':H,'l':L,'c':C}, f, protocol=5)
    return len(T)

if __name__=="__main__":
    os.makedirs(IES, exist_ok=True)
    tinte = sys.argv[1:] or sorted(s for s in os.listdir(BAZA)
                                   if os.path.isdir(os.path.join(BAZA,s,"candles_1m")))
    for s in tinte:
        t0=time.time(); n=agrega(s)
        print(f"{s:12s} {n:7d} ore  {time.time()-t0:6.1f}s", flush=True)
