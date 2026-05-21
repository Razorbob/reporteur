# Alp Report (GLPI 11)

Simple GLPI 11 plugin to:

- Upload a `.docx` template that contains placeholders.
- Generate a filled `.docx` for a `Computer`, `NetworkEquipment`, or `Rack` asset.

## Install

1. Copy folder `alpreport` into your GLPI plugins directory:
   - `<glpi>/plugins/alpreport`
2. In GLPI: `Setup > Plugins`
3. Install and enable **Alp Report**.

## Use

1. Open the plugin from **Tools / Werkzeuge â†’ Alp Report** in the GLPI menu.
2. Pick the asset (type + item).
3. Upload a `.docx` template or pick an already uploaded one.
4. Click **Generate DOCX**.

You can also click **Download sample template** on the form page to get a ready-to-use
`.docx` containing every supported placeholder. Edit it in Word, re-upload, and run.

## Supported placeholders

### Asset core

| Placeholder | Source |
|---|---|
| `{{asset_type}}` | itemtype display name |
| `{{asset_id}}` | item id |
| `{{asset_name}}` | name |
| `{{asset_serial}}` | serial |
| `{{asset_otherserial}}` | inventory number |
| `{{asset_uuid}}` | uuid |
| `{{asset_comment}}` | comment |
| `{{asset_contact}}`, `{{asset_contact_num}}` | contact info |
| `{{asset_date_creation}}`, `{{asset_date_mod}}` | timestamps |
| `{{generated_at}}` | now |

### Resolved dropdowns / aliases

| Placeholder | Source table |
|---|---|
| `{{asset_location}}` | `glpi_locations` |
| `{{asset_state}}` | `glpi_states` |
| `{{asset_manufacturer}}` | `glpi_manufacturers` |
| `{{asset_model}}` | `glpi_computermodels` / `glpi_networkequipmentmodels` |
| `{{asset_type}}` | `glpi_computertypes` / `glpi_networkequipmenttypes` |
| `{{asset_entity}}` | `glpi_entities` (full path) |
| `{{asset_network}}` | `glpi_networks` |

### Operating system *(via `glpi_items_operatingsystems`)*

- `{{asset_os}}` â€” name
- `{{asset_os_version}}` â€” version
- `{{asset_os_servicepack}}` â€” service pack
- `{{asset_os_kernel}}` â€” kernel version
- `{{asset_os_architecture}}` â€” architecture
- `{{asset_os_edition}}` â€” edition
- `{{asset_os_serial}}` â€” product key / license number stored on the OS link
- `{{asset_os_license_id}}` â€” license ID stored on the OS link

### Group / domain *(via pivot tables)*

- `{{asset_group}}` â€” comma-separated list from `glpi_groups_items` plus `groups_id` / `groups_id_tech`
- `{{asset_domain}}` â€” comma-separated list from `glpi_domains_items` plus `domains_id`

### Assigned user *(when `users_id` is set)*

- `{{asset_user_name}}` â€” display name
- `{{asset_user_login}}` â€” login
- `{{asset_user_realname}}` â€” real name + first name
- `{{asset_user_email}}` â€” default email
- `{{asset_user_phone}}` â€” phone

### Network *(primary port)*

- `{{asset_ip}}`
- `{{asset_mac}}`

### All network ports

- `{{network_ports}}` — multi-line list (one port per line) with `#logical name [type] MAC=... IP=... VLAN=...`
- `{{network_ports_count}}` — total port count

### Rack mount *(when the asset is mounted in a rack)*

- `{{asset_rack}}` — rack name
- `{{asset_rack_position}}` — U position
- `{{asset_rack_orientation}}` — `front` or `rear`

### Rack as asset *(when generating a sheet for a Rack itself)*

- `{{rack_size}}` — number of units
- `{{rack_used_units}}` — sum of `required_units` of mounted models
- `{{rack_room}}` — datacenter room name
- `{{rack_room_position}}` — position in room
- `{{rack_max_power}}`, `{{rack_mesured_power}}`
- `{{rack_max_weight}}`, `{{rack_mesured_weight}}`
- `{{rack_items}}` — multi-line list of mounted items (`U<pos> [front/rear] <Type>: <Name>`)
- `{{rack_items_count}}`

### Components

`{{components}}` â€” flat summary of every device type and its items.

For each device type you also get three placeholders:

- `{{components_<type>}}` â€” list of items, e.g. `Intel i7 (serial=ABC, frequency=3200)`
- `{{components_<type>_count}}` â€” number of items
- `{{components_<type>_serial}}` â€” comma-separated serials only

Available types: `processor`, `memory`, `harddrive`, `networkcard`, `graphiccard`,
`soundcard`, `motherboard`, `powersupply`, `drive`, `control`, `case`, `pci`,
`simcard`, `sensor`, `battery`, `firmware`, `generic`, `camera`.

### Software inventory *(Computer only, via `glpi_items_softwareversions`)*

- `{{software}}` â€” pipe-separated list of `Name Version [SN: serial]`
- `{{software_serial}}` â€” license serials linked via `glpi_items_softwarelicenses`
- `{{software_count}}` â€” number of installed software entries

### Escape hatches

- `{{field_<columnname>}}` â€” any scalar column on the asset table (e.g. `{{field_contact}}`)
- `{{hardware_<fkfield>_id}}` or `{{hardware_<fkfield>}}` â€” resolved dropdown name
  for any `_id` foreign key on the asset (e.g. `{{hardware_users_id_tech}}`)

## Notes

- Replacement is performed in `word/document.xml`, all `word/headerN.xml`, and
  all `word/footerN.xml`.
- The renderer repairs placeholders that Word splits across multiple `<w:r>` runs,
  so styled placeholders usually work — but try to keep `{{...}}` unstyled to be safe.
- Unresolved placeholders are replaced with an empty string (no literal `{{...}}`
  will leak into the output).
- All values are XML-escaped before insertion.
- CSRF protection uses a dedicated plugin-scoped session token
  (`$_SESSION['plugin_alpreport_csrf']`) to avoid collisions with GLPI's heavily
  churned global token store.

### Tabular placeholders

The following placeholders, when found in a paragraph, replace the **entire
paragraph** with a real Word table (with header row and borders):

| Placeholder         | Columns                                                  |
| ------------------- | -------------------------------------------------------- |
| `{{components}}`    | Type, Name, Manufacturer, Serial, Inventory #            |
| `{{software}}`      | Software, Version, License Serial                        |
| `{{network_ports}}` | #, Name, Type, MAC, IP, VLAN                             |
| `{{rack_items}}`    | Position, Orientation, Type, Name, Location              |

If the asset has no data for a tabular placeholder, the paragraph containing it
is left untouched and the placeholder is replaced with an empty string.

## Managing templates

Saved templates live in `<glpi-files>/_plugins/alpreport/templates/`. The form
lists each saved template with a small **×** button — click it to delete the
template from disk (with a JS confirmation prompt). New templates are added by
uploading a `.docx` file via the form.

## Supported asset types

- `Computer`
- `NetworkEquipment`
- `Rack`

Other itemtypes can be added by extending `SUPPORTED_ITEMTYPES` in
`inc/templateprocessor.class.php`.

