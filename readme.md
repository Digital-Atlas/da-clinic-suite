# Digital Atlas Clinic Suite

One plugin for Digital Atlas dermatology sites. It replaces three plugins:

| Module | Replaces | Shortcode |
|---|---|---|
| **Schema** | Dermatology EEAT Master Authority Schema v2.8 | — (JSON-LD in `<head>`) |
| **HIPAA Map** | Freshpaint Map Shortcode + Admin v2.1 | `[freshpaint_map]` |
| **Self-Scheduling** | Phreesia Self-Scheduling Widget v2.4 | `[phreesia_widget]` |

Each module is switched on or off under **Clinic Settings → Modules**. A module that is off loads no code. Its shortcode renders nothing, so pages never show raw shortcode text.

**Requires:** Advanced Custom Fields PRO (options page, repeaters, relationship fields).

## Clinic Settings

* **Modules:** the three on/off switches.
* **Self-Scheduling:** global Phreesia URL and the widget's starting height.
* **HIPAA Map:** zoom, zoom on click, marker colour, map height, location list on/off, booking link text.
* **Locations:** one row per clinic: name, phone, address, coordinates, hours, holidays, schema pages, **Booking Page URL**, optional per-location **Phreesia URL**. The schema module and the map both read from these rows, so the details only need entering once.

The Freshpaint environment ID is global (`DACS_FRESHPAINT_ENV_ID` in `da-clinic-suite.php`).

## Shortcodes

```
[freshpaint_map]
[freshpaint_map height="600px" show_cards="false" focus="2" color="#307a99" zoom="12"]
```
`focus` takes a location number (1 = first row) or part of its name.

```
[phreesia_widget]
[phreesia_widget location="2" height="400"]
```
`location` uses that location's Phreesia URL, falling back to the global one. Several widgets can sit on one page.

## Moving a site to the suite

1. Upload and activate **Digital Atlas Clinic Suite**. While an old plugin is still active, the matching module stays paused and a notice says so, so there's no duplicate schema and no shortcode clash.
2. On the first admin page load the suite migrates the old map settings and matches the old map markers to clinic locations. A matched location gets the marker's coordinates if it has none, and the marker's link as its Booking Page URL if it has none. Any marker with no matching location is listed in a notice. The Phreesia URL uses the same field, so nothing moves.
3. Deactivate and delete the three old plugins.
4. Optional: delete the old **Phreesia Connections** options page and field group under ACF. The suite now owns that field.

The old plugins' options are left in place, so reactivating one still works.

## ACF fields

`acf-fields.json` ships with the plugin and is registered as local field groups, so every site gets identical fields on update. Change fields in this file, not in a site's ACF admin, or sites will drift apart. Values are stored by field name, so existing data is kept.

Hooks:
* `dacs_treatment_post_type` (string, default `treatment`): post type for condition / procedure fields.
* `dacs_schema_treatment_post_types` (array, default `treatment`, `service`, `services`): post types that get condition / procedure schema.
* `dacs_module_enabled` (bool, module): force a module on or off in code.
* `dacs_load_acf_json` (bool): set false to manage the JSON groups yourself.

## Schema architecture

1. **Procedures:** `MedicalProcedure` with body location, preparation, and the conditions treated.
2. **Conditions:** `MedicalCondition` with codes (ICD-10, SNOMED), epidemiology, risk factors, symptoms and sub-types (`differentialDiagnosis`).
3. **Team:** `Person` + `IndividualPhysician` (physicians) or `Person`, with credentials, education, expertise (`knowsAbout`), memberships, affiliations, profiles (`sameAs`) and NPI. Linked to the clinic through `worksFor` and `practicesAt`.
4. **Clinic / locations:** `MedicalOrganization` (primary) or `MedicalClinic` with address, geo, opening hours, holidays, logo, `sameAs` and insurance.

## Updates and releases

Sites update from **GitHub Releases** on [Digital-Atlas/da-clinic-suite](https://github.com/Digital-Atlas/da-clinic-suite). Install the release zip on a site once by hand. After that, updates appear under **Plugins** like any other plugin, with auto-updates if you turn them on.

**To release a new version:**
1. Make the change and test it on a local site.
2. Bump the version in **both** places in `da-clinic-suite.php`: the `Version:` header and `DACS_VERSION`.
3. Add an entry to `changelog.md`.
4. Commit, then tag and push:
   ```
   git commit -am "v3.1.1: short description"
   git tag v3.1.1
   git push && git push --tags
   ```
5. The **Release** GitHub Action checks that the tag matches the version, lints the PHP, builds `da-clinic-suite.zip` and publishes the release. Sites see the update within about 12 hours, or right away with **Check for updates** on the Plugins screen.

To build the zip locally, run `bin/build.sh`; the zip lands in `dist/da-clinic-suite.zip`.

**wp-config.php options:**
* `define( 'DACS_GITHUB_TOKEN', 'github_pat_…' );`: required only if the repo is made private. Use a fine-grained token with read-only **Contents** access to this one repo.
* `define( 'DACS_DISABLE_UPDATES', true );`: stops a site checking for updates.
* `define( 'DACS_UPDATE_REPO', 'https://github.com/owner/repo/' );`: point a site at a fork.
