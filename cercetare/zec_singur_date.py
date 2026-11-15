import json,urllib.request,sys,time
out={}
for s in ['ZECUSDT','BTCUSDT','ETHUSDT']:
    rows=[];t=1561939200000  # 2019-07-01
    while True:
        u=f'https://api.binance.com/api/v3/klines?symbol={s}&interval=1h&startTime={t}&limit=1000'
        for k in range(5):
            try: d=json.load(urllib.request.urlopen(u,timeout=30)); break
            except Exception as e: time.sleep(3)
        if not d: break
        rows+=[(r[0],float(r[1]),float(r[2]),float(r[3]),float(r[4]),float(r[7])) for r in d]  # t,o,h,l,c,quote vol
        t=d[-1][0]+3600000
        if len(d)<1000: break
        time.sleep(0.15)
    out[s]=rows; print(s,len(rows),rows[0][0],rows[-1][0])
json.dump(out,open(sys.argv[1],'w'))
