# 🔔 WebWatch

Savas svetainių pokyčių stebėjimo įrankis (kaip „Web Alert“ Android'e), veikiantis ant bet kokio PHP hostingo (pvz. Hostinger) ir siunčiantis **push pranešimus į iPhone** arba **el. laiškus**.

## Ką moka

- **Stebėti visą puslapį arba konkretų elementą** – elementą galima pasirinkti vizualiai, tiesiog bakstelėjus pirštu puslapio peržiūroje (🎯), arba įrašyti CSS parinkiklį / XPath.
- **„Visi panašūs“** – vienu paspaudimu pažymi visas naujienų antraštes, prekes sąraše ir pan.
- **Režimai:**
  - bet koks teksto pakeitimas;
  - tik nauji įrašai (naujienoms – pašalinimai ignoruojami);
  - kai atsiras žodis / frazė („Yra sandėlyje“);
  - kai dings žodis / frazė („Išparduota“);
  - skaičius / kaina (pvz. pranešti tik kai kaina nukrenta ≥ 5 %);
  - HTML kodas (mato ir atributų, nuorodų pokyčius).
- **Jautrumas (tikslumas)** – pranešti tik kai pasikeičia ≥ X % turinio; galima ignoruoti skaičius (datas, skaitliukus) ir tekstą pagal reguliarias išraiškas.
- **Dažnis** – nuo kas 5 min. iki kartą per parą, atskirai kiekvienam puslapiui.
- **Pranešimai:** Web Push (iPhone, Android, kompiuteris). Jei push nepavyksta ar neįjungtas – automatiškai el. paštu. Arba abu.
- **Kanalai:** push, el. paštas, **Telegram**, **ntfy**, **Discord / Slack / bet koks webhook** – pasirenkama kiekvienam stebėjimui atskirai.
- **Tylios valandos** – naktį pranešimai kaupiami ir išsiunčiami ryte (vienu suvestiniu, jei jų daug).
- **Kainų istorijos grafikas** (mažiausia / didžiausia / dabartinė kaina).
- **Naujienų filtras** – pranešti tik apie naujas eilutes, kuriose yra nurodytas žodis.
- **JSON API** stebėjimas (kelias, pvz. `$.items[0].price`).
- **Puslapiai su prisijungimu** – savi slapukai (Cookie) ir HTTP antraštės; telefono arba kompiuterio naršyklės tipas.
- **JavaScript puslapiai** – per išorinę atvaizdavimo paslaugą (ScrapingBee ir pan., nebūtina).
- **Žymos, paieška**, „Viską perskaičiau“, stebėjimo kopijavimas, **eksportas / importas (JSON)**.
- Neperskaitytų pokyčių skaičius ant programėlės ikonos, automatinis atsinaujinimas grįžus į programėlę.
- Pranešimas „vėl veikia“, kai puslapis po klaidų vėl pasiekiamas; pakartotinis bandymas dėl tinklo klaidų.
- Pokyčių istorija su spalvotu skirtumų (diff) rodymu.
- Įspėja, jei puslapio nepavyksta patikrinti 3 kartus iš eilės.
- Veikia kaip programėlė (PWA) – tamsus režimas, pritaikyta telefonui.
- Jokių išorinių bibliotekų, jokios MySQL – duomenys SQLite faile.

## Reikalavimai

- PHP **8.1+** su `curl`, `openssl`, `pdo_sqlite`, `dom`, `mbstring` (Hostinger turi viską).
- **HTTPS** (Hostinger suteikia nemokamą SSL) – be jo push neveiks.
- Cron užduotys (Hostinger: hPanel → Advanced → Cron Jobs).

## Įdiegimas Hostinger

1. **Sukurkite subdomeną**: hPanel → Domains → Subdomains, pvz. `watch.jusudomenas.lt`.
   Įsitikinkite, kad jam įjungtas SSL (hPanel → Security → SSL).
2. **Įkelkite failus**: hPanel → Files → File Manager → atidarykite subdomeno katalogą
   (pvz. `domains/jusudomenas.lt/public_html/watch`) → Upload → įkelkite `webwatch.zip` → Extract.
   Visi failai (`index.php`, `cron.php`, `lib/`, `data/` ir t.t.) turi būti tiesiai tame kataloge.
3. **PHP versija**: hPanel → Advanced → PHP Configuration – pasirinkite 8.2 ar naujesnę.
4. **Atidarykite** `https://watch.jusudomenas.lt` – susikurkite slaptažodį.
5. **Cron**: Nustatymuose (⚙️) rasite paruoštą komandą. hPanel → Advanced → Cron Jobs →
   Custom → įklijuokite, pvz.:
   ```
   /usr/bin/php /home/u123456789/domains/jusudomenas.lt/public_html/watch/cron.php
   ```
   dažnis: kas 5 minutes (`*/5 * * * *`).
6. **El. paštas** (nebūtina, bet rekomenduojama): hPanel → Emails → susikurkite dėžutę,
   pvz. `webwatch@jusudomenas.lt`. WebWatch Nustatymuose įveskite SMTP:
   `smtp.hostinger.com`, prievadas `465`, SSL, vartotojas – tas el. paštas.
   Spauskite „Siųsti bandomąjį laišką“.

## Push pranešimai iPhone

Apple leidžia web push tik programoms, pridėtoms į pradžios ekraną (iOS 16.4+):

1. Atidarykite savo WebWatch adresą **Safari**.
2. **Bendrinti** (□↑) → **Add to Home Screen / Pridėti prie pradžios ekrano**.
3. Atidarykite WebWatch **iš pradžios ekrano**, prisijunkite.
4. ⚙️ Nustatymai → **🔔 Įjungti pranešimus** → Leisti.
5. „Siųsti bandomąjį“ – turi atkeliauti pranešimas.

Kompiuteryje ar Android – tiesiog paspauskite „Įjungti pranešimus“ naršyklėje.
Galima registruoti kelis įrenginius – pranešimai eis į visus.

## Naudojimas

1. **＋ Naujas** → įklijuokite adresą.
2. Pasirinkite **Visą puslapį** arba **Tik elementą** → **🎯 Pasirinkti elementą** → bakstelėkite vietą.
   - **⬆ Didesnė sritis** – pažymi tėvinį elementą.
   - **☰ Visi panašūs** – pažymi visus to paties tipo elementus (naujienų sąrašui).
   - **✓ Naudoti**.
3. Pasirinkite, **kada pranešti**, jautrumą ir dažnį.
4. **🔍 Išbandyti** – pamatysite tiksliai tą tekstą, kurį stebės WebWatch.
5. **Išsaugoti** – iškart užfiksuojama pradinė būsena.

### Patarimai

- Jei puslapyje keičiasi data, laikas ar peržiūrų skaičius – pažymėkite „Ignoruoti skaičių pokyčius“
  arba pasirinkite tik reikiamą elementą.
- Kainai stebėti rinkitės tik kainos elementą ir režimą „Skaičius / kaina“, kryptį „Tik sumažėjus“.
- CSS pavyzdžiai: `#price`, `.product-title`, `ul.news > li`, `table tr:nth-child(2) td`,
  `div:contains("Registracija")`. XPath: `//h1`, `//*[@id="main"]//a`.

### Apribojimai

- WebWatch mato serverio grąžinamą HTML (kaip „peržiūrėti šaltinį“). Puslapiai, kurių turinys
  sukuriamas tik per JavaScript (kai kurios SPA programos), gali būti tušti – „Išbandyti“ tai parodys.
- Svetainės už prisijungimo ar su Cloudflare „patikra, ar esate žmogus“ gali neveikti.

## Failų struktūra

```
index.php        – vartotojo sąsaja (sąrašas, redagavimas, istorija, nustatymai)
api.php          – AJAX veiksmai (push registracija, testai, tikrinimas)
preview.php      – puslapio peržiūra elementų parinkikliui (be svetainės skriptų)
cron.php         – periodinis tikrinimas
sw.js            – service worker (push pranešimai)
manifest.json    – PWA aprašas
assets/          – CSS, JS, parinkiklio skriptas
icons/           – programėlės ikonos
lib/             – logika (uždrausta prieiga iš interneto)
data/            – SQLite duomenų bazė ir sesijos (uždrausta prieiga iš interneto)
config.sample.php – nebūtini nustatymai (pervadinkite į config.php)
```

## Atsarginė kopija ir atnaujinimas

- Visi duomenys yra `data/webwatch.sqlite` – nusikopijuokite šį failą.
- Atnaujinant – perrašykite visus failus **išskyrus** `data/` katalogą ir `config.php`.
- Pamiršote slaptažodį? File Manager'yje kataloge `data/` sukurkite tuščią failą `reset-password`
  ir atidarykite WebWatch – bus pasiūlyta sukurti naują slaptažodį (stebėjimai išliks).
