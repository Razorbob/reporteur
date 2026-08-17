# Alp Import (GLPI 11)

Initial GLPI 11 plugin boilerplate for importing data from spreadsheet files.

## Install

1. Copy folder `alpimport` into your GLPI plugins directory:
   - `<glpi>/plugins/alpimport`
2. In GLPI: `Setup > Plugins`
3. Install and enable **Alp Import**.

## Use

1. Open **Tools / Werkzeuge → Alp Import**.
2. Upload an `.xlsx` file (or select an already uploaded one).
3. Click **Import XLSX**.

The current importer:

- processes the main sheet `Geräte, Netzwerke und IP-Adress`
- upserts GLPI `Computer` assets by name
- maps `Gerät-ID` to `otherserial`
- maps `hostname` to primary network name
- maps `Typ` and `Rechnerart` to type/model dropdown dictionaries
- maps `Stw | Ort` to a group and links the computer
- maps `Betriebssystem` + `Build` to operating system relations
- maps software columns (`eingesetzte Software 1..12`) to software inventory relations
- imports all IP-like values as additional interface IP addresses
- shows sheet/header preview + import summary

`.xls` files are listed for convenience, but native import currently requires
`.xlsx` (convert `.xls` before import).

Stored files are saved in:

- `<glpi-files>/_plugins/alpimport/imports/`

## Mapping implementation

Business mapping and persistence logic lives in:

- `inc/xlsximporter.class.php`


## Known issues

- Only accepts `.xlsx` files, it should also work with `.xls`
