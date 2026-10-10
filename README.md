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
- Duomenys SQLite faile (nieko konfigūruoti nereikia) **arba** MySQL / MariaDB, jei norite (žr. „Duomenų bazė“).

## Reikalavimai

- PHP **8.1+** su `curl`, `openssl`, `pdo_sqlite`, `dom`, `mbstring` (Hostinger turi viską).
  MySQL naudojimui – dar `pdo_mysql` (Hostinger irgi turi).
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

### Svetainės, kurios blokuoja robotus (Cloudflare ir pan.)

Android programėlės tikrina iš paties telefono, o WebWatch – iš serverio, kurį dalis svetainių blokuoja.
WebWatch tai sprendžia automatiškai – bando būdus iš eilės ir įsimena veikiantį:

1. **Tiesiogiai kaip tikra naršyklė** (naršyklės antraštės, slapukai tarp tikrinimų, kitas naršyklės tipas, pagrindinio puslapio „apšildymas“).
2. **Jina Reader** – nemokama tikra naršyklė debesyje (galima išjungti).
3. **Apėjimo paslauga** su API raktu – ScrapingBee / ScraperAPI / ZenRows. Patikimiausia prieš griežtas apsaugas, naudojama tik užblokuotiems puslapiams.
4. **Namų kompiuteriai** (žr. žemiau) – kai serverio adresą svetainė blokuoja, tikrinama per jūsų pačių kompiuterius.

Nustatymai → **🛡️ Apsaugos nuo robotų apėjimas** → „Tikrinti visus būdus“ parodo, kuris būdas konkrečiai svetainei veikia.
Puslapiams, kuriems reikia prisijungti: redaguojant → Papildomi → **„Įklijuoti iš kompiuterio naršyklės (cURL)“**.

### Namų kompiuteriai (tikrinimo taškai)

Savo kompiuterius skirtingose vietose galima įdarbinti kaip tikrinimo taškus – jie parsiunčia puslapius per
savo interneto ryšį (kaip Uptime Kuma ar Pingdom nutolę mazgai). Naudinga savo svetainėms stebėti iš kelių
vietų arba kai hostingo serverio adresą svetainė blokuoja.

1. Nustatymai → **🖥️ Namų kompiuteriai** → įrašykite pavadinimą (pvz. „Namai“) → **Pridėti**.
2. Spauskite **Įdiegti** ir paleiskite parodytą komandą tame kompiuteryje:
   - **Windows** – PowerShell lange (viena eilutė, autostartas per suplanuotą užduotį);
   - **Mac / Linux** – Terminale (reikia Python 3; autostartas per launchd / systemd).
3. Kompiuteris pats prisijungs (žalias taškas) ir veiks fone net po perkrovimo.
4. Stebėjime → Papildomi → **„Iš kur tikrinti“** pasirinkite:
   - *Tik hostingo serveris* (numatyta, kai taškų nėra);
   - *Serveris, o jei nepavyksta – namų kompiuteriai* (rekomenduojama);
   - *Tik namų kompiuteriai*.

**Perdavimas kitam (failover):** darbas siunčiamas kompiuteriams eilės tvarka (tvarką keičiate rodyklėmis ↑↓).
Jei pirmas neprisijungęs ar jo interneto ryšys neveikia – bandomas antras, tada trečias. Svetainės atsakymas
(net klaidos kodas) laikomas rezultatu; kitas kompiuteris imamas tik tada, kai sutrinka pats kompiuteris.

**Sudėtingos apsaugos (Cloudflare ir pan.):** jei svetainė blokuoja net namų kompiuterį (HTTP 403,
„Just a moment“) – tai apsauga tikrina ne IP, o užklausos „pirštų atspaudą“. Tame kompiuteryje įdiekite
**Google Chrome** arba **Microsoft Edge**; agentas tokius puslapius automatiškai parsiųs per tą naršyklę
(paleistą fone, be lango), su tikru naršyklės atspaudu ir JavaScript vykdymu. Įprastus puslapius jis ir toliau
ims greituoju būdu, o naršyklę pasitelks tik užblokuotiems.

Agento programa (`agent/agent.py`, `agent/agent.ps1`) parsiunčia nurodytą puslapį ir grąžina jį serveriui.
Python agentui papildomų bibliotekų nereikia; naršyklės režimui – įdiegta Chrome/Edge. Pašalinti: tą pačią
komandą su `--uninstall` (arba `-Uninstall`).

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

## Vieta serveryje (automatinis valymas)

- **Tekstinė pakeitimų istorija** saugoma suspausta (~4–8 k. mažiau vietos): numatytai 1 metus,
  ne daugiau 1000 pakeitimų vienam stebėjimui. Laikas keičiamas Nustatymai → „Vieta serveryje".
- **Ekrano nuotraukos** (didžiausios) – tik paskutinių 20 pakeitimų vienam stebėjimui.
- Kartą per parą (cron) automatiškai ištrinama tik tai, kas viršija ribas, taip pat: žurnalas senesnis nei
  60 d., robotų / neprisijungusių lankytojų sesijos, seni prisijungimo bandymai; SQLite failas suspaudžiamas.
- Nustatymuose matyti, kiek vietos užima DB, nuotraukos ir sesijos; yra mygtukas „Išvalyti dabar".

## Duomenų bazė (SQLite arba MySQL)

Numatytai naudojama **SQLite** – nieko daryti nereikia, viskas veikia iš karto.

Jei įdarbinate **namų kompiuterius** ir stebite daug puslapių, gali pasitaikyti „database is locked“
(SQLite prastai tvarkosi, kai vienu metu rašo daug procesų). Tokiu atveju pereikite prie **MySQL / MariaDB**:

1. Hostinger hPanel → **Databases → MySQL Databases** → sukurkite duombazę ir vartotoją.
2. Įrašykite prisijungimo duomenis į `.env` failą – **vienu katalogu aukščiau** nei programa
   (ne viešai; tinka ir programos kataloge – `.htaccess` jį užrakina):
   ```
   DB_HOST=localhost
   DB_DATABASE=u123456_webwatch
   DB_USERNAME=u123456_webwatch
   DB_PASSWORD=jusu_slaptazodis
   ```
   (arba `config.php` faile – `WW_DB_HOST/NAME/USER/PASS`, pavyzdys `config.sample.php`).
3. Atidarykite WebWatch – lentelės sukuriamos, o **visi esami duomenys perkeliami iš SQLite automatiškai**
   (stebėjimai, istorija, kompiuteriai su raktais – jų perdiegti nereikia). SQLite failas paliekamas atsargai.
4. Nustatymai → **Duomenų bazė** parodo, kuri DB naudojama ir iš kur paimti nustatymai.

Nuo šios versijos ir su SQLite užraktų problema gerokai sumažinta (WAL, ilgesnis laukimas, automatinis
pakartojimas), tad daugeliu atvejų SQLite pakanka.

## Atsarginė kopija ir atnaujinimas

- SQLite atveju visi duomenys yra `data/webwatch.sqlite` – nusikopijuokite šį failą (MySQL – per hPanel).
- Atnaujinant – perrašykite visus failus **išskyrus** `data/` katalogą ir `config.php`.
- Pamiršote slaptažodį? File Manager'yje kataloge `data/` sukurkite tuščią failą `reset-password`
  ir atidarykite WebWatch – bus pasiūlyta sukurti naują slaptažodį (stebėjimai išliks).
