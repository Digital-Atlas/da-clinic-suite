# Digital Atlas Clinic Suite — Changelog

**Current Version:** 3.2.8

---

### [v3.2.8] - Fix: single-location practices keep their address on the homepage
* **Single-location practices:** 3.2.7 stopped printing the primary office on the homepage. For a practice with only one location and no page linked to it, that left the address, hours and coordinates out of the schema entirely. When there is exactly one location, the homepage prints its `MedicalClinic` again, under the practice organization. Multi-location practices are unchanged: each office appears on its own page.

### [v3.2.7] - One practice, equal offices
* **The practice is one organization:** Yoast's Organization (`#organization`) is now the practice. Clinic Suite enriches it as a `MedicalOrganization` with the Practice Logo, Global sameAs links (merged with Yoast's), the primary location's phone as the main number, every location's Area Served, the Parent Organization, and every office as a `department`. On the homepage it also lists the Team providers as `employee`. Without Yoast's Organization, the plugin prints the same node itself. Replaces 3.2.6's name-matching merge, which only worked when the primary location had the same name as Yoast's Organization. Filters: `dacs_organization_id`, `dacs_schema_organization_type`, `dacs_schema_organization`.
* **Every office is equal:** each location, including the one marked primary, is a `MedicalClinic` at `home#fragment` with its own address, hours, phone and page URL, and the practice as `parentOrganization`. The homepage no longer prints the primary office as if it were the whole business. "Is Primary Location?" now only picks the practice's main phone number.
* **Providers work for the practice:** `worksFor` on Team pages, treatment pages, blog authors and the employee list now points at the organization, not the primary office.
* **New "Practices at" field on Team posts** (side panel): tick the offices a provider regularly works at. Physicians get `practicesAt`, everyone else `workLocation` (the schema.org property for a Person). Leave it blank when a provider's offices change often; they are then linked to the practice only, never to a guessed office. Choices come from Clinic Settings → Locations and are stored by fragment, so reordering locations is safe (renaming a fragment is not). Filter: `dacs_provider_locations`.
* **Insurance and price range stay on the offices:** `paymentAccepted` and `priceRange` are local-business properties, so they sit on each location, not the organization. Global sameAs moves from the locations to the organization.
* **Blog author names** use the Provider name field, like everywhere else.
* `dacs_primary_clinic_id()` still exists for custom code and now returns the organization @id.

### [v3.2.6] - Clinic schema: one local business, not two organisations
* **One entity for the clinic:** when Yoast's Organization has the same name as the primary clinic, the clinic now uses Yoast's `@id`, so the two merge into one business. Before, the clinic named Yoast's Organization (itself) as its `parentOrganization`. When the names differ, Yoast's Organization is still the parent. Filter: `dacs_primary_clinic_id`.
* **Parent group setting:** new **Parent Organization** and **Parent Organization URL** fields under Clinic Settings → Global Schema Defaults (for example "Forefront Dermatology"). When filled in, the primary clinic gets that group as `parentOrganization`.
* **`MedicalClinic` for the primary clinic:** it was `MedicalOrganization`, which isn't a local business type, so search engines could ignore its address, coordinates, opening hours and payment details. `MedicalClinic` is both a medical organisation and a local business. Treatment, provider and blog references use the same type. Filter: `dacs_schema_clinic_type`.
* **Settings that were never output:** Price Range now outputs as `priceRange` and a location's Area Served as `areaServed`. The clinic also gets `url` (the homepage, or a satellite's first linked page) and `image` (the logo).
* **Cleaner values:** US phone numbers are output as `+1-502-339-2922` (the field itself, and what visitors see, is unchanged). Coordinates are numbers, not text. `medicalSpecialty` uses schema.org's `Dermatology` value.
* **Provider names:** staff lists, treatment pages and provider pages use the **Provider name** field (for example "Michelle Hannigan, APRN"), falling back to the page title.

### [v3.2.5] - Fix: editors can't save posts on UiCore Blocks sites (temporary)
* **Editors and authors can save again:** UiCore Blocks saves each post's CSS through its own REST route just before the block editor saves the post, and that route only allows administrators. For everyone else every save failed ("Failed to update UiCore Blocks Assets." / "Publishing failed. Sorry, you are not allowed to do that."), including drafts and posts with no UiCore blocks. Clinic Suite now also allows that route for users who can edit the post (or `edit_theme_options` for widget areas). UiCore's own check is unchanged for everything else.
* **No markup through CSS:** for users without `unfiltered_html`, `<` is removed from the CSS and font data and preload image URLs are sanitised before UiCore stores them, because UiCore prints that CSS unescaped.
* Temporary until UiCore fixes the check upstream (`includes/uicore-compat.php`). Does nothing on sites without UiCore Blocks. Turn off per site with `add_filter( 'dacs_uicore_save_styles_fix', '__return_false' );`.

### [v3.2.4] - Fix: service pages crashing on sites without treatment categories
* **No more critical error on treatment pages:** on sites whose treatments have no `treatment-category` taxonomy (for example Radiance, which uses a `services` post type), the Schema module crashed every treatment page with "There has been a critical error". It now treats those pages as having no condition/procedure category and outputs the page schema as normal.

### [v3.2.3] - Blog author detection
* **Bylines found more reliably:** the Blog Schema module now recognises "By …" with a capital B (most recent posts), a "Dr." before the name, middle initials ("Nnenna G. Agim"), short names and nicknames ("Becca Marcus", "Coco Lowenfield"), one-letter typos ("Jennifer Houk"), "Q&A with …", and posts that simply open with the provider's name. Before, only a lowercase "by" matched, so many posts fell back to the clinic as author.
* A match still needs the surname plus a given name or nickname (or "Dr."), so ordinary sentences such as "caused by …" don't pick a provider. A post's **Written by** field always wins.

### [v3.2.2] - Works with each site's own post types
* **Condition / procedure fields on any treatment post type:** the EEAT field groups now attach to `treatment`, `services` and `service`, the same types the schema already covered. Before, they attached to one type only (`treatment` unless a site set `dacs_treatment_post_type`), so on sites like Seacoast (`service`) the fields vanished from the editor and the schema had nothing to read. Relationship fields that point at treatments list whichever of those types the site has. New filter: `dacs_treatment_post_types` (array). The old `dacs_treatment_post_type` filter still works and narrows the list to the one type it returns.
* **Display on Pages accepts location posts:** a location's "Display on Pages" field lists pages plus any `location`, `locations`, `clinic` or `clinics` post type the site has, so sites with a location post type can link their location pages (and those links survive saving Clinic Settings). Filter: `dacs_location_page_post_types`.

### [v3.2.1] - Schema links up with Yoast
* **One page entity, not two:** the treatment page's `MedicalWebPage` now has the page URL as its `@id`, the same ID as Yoast's WebPage, so the two merge instead of appearing as separate pages.
* **Team pages:** the bare `MedicalWebPage` (no ID) is now a `ProfilePage` with the page URL as its `@id` and the provider as its `mainEntity`.
* **Clinic → Yoast Organization:** when Yoast outputs an Organization, the primary clinic gets it as `parentOrganization`, so the homepage no longer has two unrelated organisations. Filter: `dacs_yoast_organization_id` (return null to turn this off).
* **Fragment fix:** location fragments are normalised to `#name`, so a fragment saved as `/#orlando-clinic` no longer produces a `//#orlando-clinic` @id. Applies to the schema and blog modules.
* Tip: in Yoast, set **Settings → Content types → Treatments → Schema → Page type** to "Medical Web Page" and **Team** to "Profile Page" so both plugins name the page type the same way.

### [v3.2.0] - Blog Schema
* **New Blog Schema module** (Clinic Settings → Modules, on by default). Blog posts get a `BlogPosting` whose author is a Team provider instead of the WordPress user. The author comes from the new **Written by** field, or else the provider named in a "by …" byline near the top of the post, or else the clinic.
* **Medical review:** the new **Medically reviewed by** and **Review date** fields add `reviewedBy` and `lastReviewed` to the post's WebPage. Nothing is added unless a reviewer is set.
* **Works with Yoast SEO:** the module edits Yoast's schema graph rather than adding a second one. It changes Article to BlogPosting, points the author at the provider, and removes the WordPress user's Person node. Without Yoast it prints its own WebPage + BlogPosting graph.
* **Clinic providers:** the primary clinic entity now lists the Team providers as `employee` (Person / IndividualPhysician with name, job title, profile URL and `worksFor`).
* Filters: `dacs_blog_post_types`, `dacs_provider_post_type`, `dacs_blog_author`, `dacs_blog_reviewer`, `dacs_schema_clinic_employees`.

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