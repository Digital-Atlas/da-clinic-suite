# Digital Atlas Clinic Suite — Changelog

**Current Version:** 3.1.0

---

### [v3.1.0] - Updates from GitHub
* **Auto-updates:** the plugin now checks GitHub Releases for `Digital-Atlas/da-clinic-suite` (Plugin Update Checker v5.6, bundled in `lib/`). WordPress shows "Update available" like any other plugin and installs the release's `da-clinic-suite.zip`.
* **wp-config options:** `DACS_UPDATE_REPO` (use a different repo), `DACS_GITHUB_TOKEN` (needed only if the repo is private), `DACS_DISABLE_UPDATES` (opt a site out).
* **Release automation:** pushing a `v*` tag runs a GitHub Action. It checks that the tag matches the plugin version, lints the PHP, builds the zip and publishes the release.
* **Cache busting:** asset URLs include each file's modification time, so changed CSS and JS reach visitors straight after an update.

### [v3.0.0] - Clinic Suite
* **Three plugins merged:** Dermatology EEAT Master Authority Schema (v2.8), Freshpaint Map Shortcode + Admin (v2.1) and Phreesia Self-Scheduling Widget (v2.4) are now one plugin, `da-clinic-suite`.
* **Clinic Settings:** "Clinic Schema" is renamed "Clinic Settings" (same page slug). It has new Modules, Self-Scheduling and HIPAA Map tabs, with Enable / Disable switches for Schema, HIPAA Map and Self-Scheduling.
* **Shared locations:** the map now builds its markers from the Locations rows: name, address, phone, hours (grouped, e.g. "Mon, Tue, Thu: 7:30 AM – 4:30 PM"), coordinates and a new Booking Page URL. The separate Freshpaint marker list is retired. Locations can also have their own Phreesia URL (`[phreesia_widget location="2"]`).
* **Legacy-plugin guard:** a module stays paused, with a notice, while its old plugin is active.
* **Migration:** the old map settings move across, and the old markers are matched to locations. Markers with no matching location are listed in a notice.
* **ACF fields bundled:** `acf-fields.json` (with the new location fields) and the suite's own settings now load from the plugin as local field groups.
* **Map fixes:** the marker colour now applies (it was coerced to 0). Zoom on click and marker colour now save. The map no longer errors when the location list is off. Popup content is rendered as text, not HTML. Several maps can sit on one page. The stacked layout works on mobile. The location list is keyboard-accessible.
* **Scheduling fixes:** jQuery is no longer required. Scripts and styles load only on pages with a widget (before, they loaded site-wide). Several widgets can sit on one page, and Phreesia messages are matched to the widget that sent them. The spinner always clears. UTM pass-through and dataLayer events are unchanged.
* **Functions renamed:** schema functions now use the `dacs_` prefix (they were `gd_`), so the suite and the old plugin can be installed side by side.

---

## Earlier history (Dermatology EEAT Master Authority Schema)

### [v2.8] - IndividualPhysician & Provider Authority Fields
* **IndividualPhysician:** schema.org v24 redefined `Physician` as a MedicalOrganization (a doctor's office). Providers with `provider_type` = Physician now output `["Person","IndividualPhysician"]`. The ACF value is unchanged, so existing data needs no edits. Applies to Team pages, treatment `provider` entries, and condition `reviewedBy`.
* **practicesAt:** IndividualPhysician providers now link to the primary clinic `@id` through `practicesAt`, alongside `worksFor`.
* **Provider Authority Fields:** Team pages now output the stored ACF fields `knowsAbout` (one per line from `knows_about`), `memberOf` (Organization with optional `sameAs`), `affiliation` (Organization), and `sameAs` (from `provider_profiles_sameas`).
* **Entity Linking:** Treatment `provider` entries and condition `reviewedBy` now carry the provider's `#provider` `@id`, so they resolve to the same entity as the Team page.
* **@id Fix:** The clinic and location `@id`s now use `trailingslashit(get_home_url())`, giving `https://site.com/#main-clinic` instead of `https://site.com#main-clinic`.

### [v2.7] - Empty Value Suppression
* **Recursive Pruning:** Added `gd_schema_prune()`, which runs on every JSON-LD block before output. It strips `null`, empty and whitespace-only strings, and empty arrays at any depth, and removes nodes left with only `@type` (e.g. an empty credential or PostalAddress). `false` and `0` are kept, so `isAcceptingNewPatients: false` still renders.
* **Unified Output:** All three blocks (Treatment, Team, Location) now print through `gd_print_schema()`, which skips the `<script>` tag entirely if nothing is left.
* **Location Guards:** `address` is left out when street, city, state and zip are all blank, so a country-only address no longer renders. `geo` renders only when both latitude and longitude are set.
* **Array Fix:** Lists filtered with `array_filter` (`alternateName`, `sameAs`, `paymentAccepted`) are re-indexed, so they now render as JSON arrays instead of objects.
* **Team @type:** The duplicate `["Person","Person"]` is collapsed to one entry when `provider_type` is Person.

### [v2.6] - Relational Codes & SNOMED Expansion
* **Fork A (Procedures) Code Injection:** Added a loop to pull `icd-10` and `snowmed` values from the `codes` repeater directly into the `$procedure_entity` node.
* **Fork B (Conditions) Main Post Fix:** Resolved an oversight where the main condition page was skipping its own clinical codes. It now correctly pulls `icd-10` and `snowmed` from the `codes` repeater on the parent post.
* **Sub-Type SNOMED Support:** Updated the relational `sub_types` loop to extract `snowmed` codes in addition to `icd-10` from child condition pages.

### [v2.5] - Procedure Condition Simplification
* **Repeater Refactoring:** Streamlined the `medical_conditions_repeater` inside Fork A to only process `internal_condition` sub-fields (Post Objects). Removed deprecated legacy array logic.
* **Manual Condition Input:** Introduced support for the new `medical_conditions_outside_objects` textarea field in Fork A. The script now automatically separates line breaks into individual `MedicalCondition` nodes containing `name` and `relevantSpecialty`.
* **Preservation Guarantee:** Ensured zero alterations to the core `MedicalWebPage` wrapper, provider loops, and satellite clinic injections.

### [v2.4] - The Relational Hub Upgrade
* **Dynamic Sub-Types:** Rebuilt Fork B (Conditions) to act as a "Hub." Added a loop to process the `sub_types` ACF relationship field, allowing parent conditions (e.g., Skin Cancer) to dynamically extract and list child conditions (e.g., Melanoma) within its `differentialDiagnosis` array.
* **Bidirectional Code Extraction:** Programmed the hub to reach into child posts and pull their specific `icd-10` codes to strengthen the parent page's clinical authority.
* **Strict Schema Domains:** Ensured properties align strictly with Schema.org domains (e.g., grouping `MedicalCode` under `MedicalCondition` nodes rather than forcing them into procedures) to pass the Google Rich Results validator.
* **Restoration:** Confirmed the preservation of all legacy V2.3 features, including Anatomy mapping, Symptoms, Causes, Shared Providers, and Team/Satellite Clinic generation.

### [v2.3] - Base E-E-A-T Architecture
* Established the core `MedicalWebPage` and `mainEntity` JSON-LD structure.
* Implemented multi-location discovery and primary clinic logic.
* Added standard `MedicalProcedure` and `MedicalCondition` forks.
* Integrated the Shared Providers logic to link `Physician` nodes with NPIs to their respective treatments and clinics.