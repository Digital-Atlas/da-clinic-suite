<?php
/**
 * Module: Schema (EEAT Master Authority Schema, formerly the Dermatology-EEAT-Schema plugin v2.8).
 * Outputs JSON-LD for the clinic / locations, Team providers, and condition / procedure treatment pages.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Post types treated as condition / procedure pages. Filter: dacs_schema_treatment_post_types. */
function dacs_schema_treatment_post_types() {
    return (array) apply_filters( 'dacs_schema_treatment_post_types', array( 'treatment', 'service', 'services' ) );
}

// --- Provider type mapping (v2.8) ---
// schema.org v24+ redefined Physician as a MedicalOrganization; individual doctors are IndividualPhysician.
// The ACF value stays "Physician" so existing data keeps working.
if ( ! function_exists('dacs_provider_schema_type') ) {
    function dacs_provider_schema_type( $acf_type ) {
        return ( $acf_type === 'Physician' ) ? 'IndividualPhysician' : ( $acf_type ?: 'Person' );
    }
}

// --- 1b. Empty-Value Pruning Helpers ---
/**
 * Recursively strips empty values from a schema array so nothing renders blank.
 *  - Removes null, empty / whitespace-only strings, and empty arrays.
 *  - Removes typed nodes left with nothing but "@type" (e.g. an empty PostalAddress).
 *  - Re-indexes list arrays so they encode as JSON arrays, not objects.
 *  - Keeps false and 0 (e.g. isAcceptingNewPatients: false is meaningful).
 */
if ( ! function_exists( 'dacs_schema_prune' ) ) {
    function dacs_schema_prune( $data ) {
        if ( ! is_array( $data ) ) {
            return is_string( $data ) ? trim( $data ) : $data;
        }

        // Integer-keyed arrays are lists (array_filter can leave gaps like 0, 3).
        $is_list = ( $data === [] ) || ( count( array_filter( array_keys( $data ), 'is_int' ) ) === count( $data ) );
        $clean   = [];

        foreach ( $data as $key => $value ) {
            $value = dacs_schema_prune( $value );
            if ( $value === null || $value === '' || $value === [] ) continue;
            $clean[ $key ] = $value;
        }

        if ( $is_list ) return array_values( $clean );

        // A node with only @type / @context carries no data — drop it.
        if ( ! array_diff( array_keys( $clean ), [ '@type', '@context' ] ) ) return [];

        return $clean;
    }
}

if ( ! function_exists( 'dacs_print_schema' ) ) {
    function dacs_print_schema( $schema ) {
        $schema = dacs_schema_prune( $schema );
        if ( empty( $schema ) ) return;
        echo "\n<script type='application/ld+json'>" . json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "</script>\n";
    }
}

add_action( 'wp_head', 'dacs_inject_schema' );

function dacs_inject_schema() {
    $post_id = get_the_ID();
    
    // --- 2. MULTI-LOCATION DISCOVERY ---
    $locations = get_field('clinic_locations', 'option');
    $primary_location = null;
    $current_page_location = null;

    if ( is_array($locations) ) {
        foreach ( $locations as $loc ) {
            if ( !empty($loc['is_primary']) ) {
                $primary_location = $loc;
            }
            if ( is_array($loc['location_pages']) && in_array($post_id, $loc['location_pages']) ) {
                $current_page_location = $loc;
            }
        }
    }

    if ( is_front_page() && $primary_location ) {
        $current_page_location = $primary_location;
    }

    // --- 3. GLOBAL ENTITY LOGIC ---
    $base_url = trailingslashit( get_home_url() );
    $primary_fragment = dacs_location_fragment( $primary_location['location_fragment'] ?? '', '#main-clinic' );
    $clinic_id = $base_url . $primary_fragment;
    $entity_type = "MedicalOrganization"; 

    // --- 4. FORK: TREATMENT POST TYPE ---
    if ( is_singular( dacs_schema_treatment_post_types() ) ) {
        $categories = wp_get_post_terms( $post_id, 'treatment-category', array( 'fields' => 'slugs' ) );
        $is_procedure = in_array( 'procedure', $categories );
        $is_condition = in_array( 'condition', $categories );
        $linked_providers = get_field('treatment_providers', $post_id);
        $clinical_name = get_field('clinical_name', $post_id);

        $treatment_schema = [
            "@context" => "https://schema.org",
            "@type" => "MedicalWebPage",
            // Same @id as Yoast's WebPage (the permalink), so the two merge into one page entity.
            "@id" => get_permalink($post_id),
            "name" =>  $clinical_name ?: get_the_title($post_id),
            "description" => get_field('procedure_description', $post_id) ?: get_field('condition_description', $post_id),
            "lastReviewed" => get_the_modified_date('c', $post_id),
            "mainEntity" => []
        ];

        // FORK A: PROCEDURE
        if ( $is_procedure ) {
            $body_locations = get_field('procedure_body_location', $post_id);
            $procedure_entity = [
                "@type" => get_field('medical_procedure_type', $post_id) ?: "MedicalProcedure", 
                "@id" => get_permalink($post_id) . "#procedure", 
                "name" => $clinical_name ?: get_the_title($post_id),
                "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"],
                "provider" => ["@type" => $entity_type, "@id" => $clinic_id]
            ];
            
            if ( is_array($body_locations) ) $procedure_entity["bodyLocation"] = $body_locations;
            $alts = get_field('alternate_names', $post_id);
            if (!empty($alts)) $procedure_entity["alternateName"] = array_filter(array_map('trim', explode("\n", $alts)));
            $prep = get_field('procedure_preparation', $post_id);
            if (!empty($prep)) $procedure_entity["preparation"] = $prep;

            // NEW: Codes Repeater for Procedures
            $procedure_codes = get_field('codes', $post_id);
            if ( is_array($procedure_codes) ) {
                foreach ( $procedure_codes as $code_row ) {
                    if ( !empty($code_row['icd-10']) ) {
                        $procedure_entity["code"][] = ["@type" => "MedicalCode", "codeValue" => trim($code_row['icd-10']), "codingSystem" => "ICD-10-CM"];
                    }
                    if ( !empty($code_row['snowmed']) ) {
                        $procedure_entity["code"][] = ["@type" => "MedicalCode", "codeValue" => trim($code_row['snowmed']), "codingSystem" => "SNOMED-CT"];
                    }
                }
            }

            $treatment_schema["mainEntity"][] = $procedure_entity;

            // 1. Process Internal Post Objects (Repeater)
            $condition_repeater = get_field('medical_conditions_repeater', $post_id);
            if (is_array($condition_repeater)) { 
                foreach ($condition_repeater as $row) { 
                    $c_raw = $row['internal_condition'] ?? null;
                    if ( !empty($c_raw) ) {
                        $c_posts = is_array($c_raw) ? $c_raw : [$c_raw];
                        foreach ($c_posts as $c_item) {
                            $c_id = (is_object($c_item)) ? $c_item->ID : (is_numeric($c_item) ? $c_item : 0);
                            if ($c_id > 0) { 
                                $c_node = ["@type" => "MedicalCondition", "@id" => get_permalink($c_id) . "#condition", "name" => get_the_title($c_id), "url" => get_permalink($c_id), "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"]];
                                $c_desc = get_field('condition_description', $c_id) ?: get_the_excerpt($c_id);
                                if (!empty($c_desc)) $c_node["description"] = trim($c_desc);
                                $treatment_schema["mainEntity"][] = $c_node; 
                            }
                        }
                    }
                } 
            }

            // 2. Process Manual Text Entries (Textarea)
            $outside_conditions = get_field('medical_conditions_outside_objects', $post_id);
            if ( !empty($outside_conditions) ) {
                $manual_conditions = array_filter(array_map('trim', explode("\n", $outside_conditions)));
                foreach ( $manual_conditions as $m_name ) {
                    $treatment_schema["mainEntity"][] = [
                        "@type" => "MedicalCondition", 
                        "name" => $m_name, 
                        "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"]
                    ];
                }
            }
        }

       // --- FORK B: CONDITION ---
        elseif ( $is_condition ) {
            $raw_anatomy = get_field('condition_associated_anatomy', $post_id);
            $anatomy_list = [];
            if ( !empty($raw_anatomy) ) {
                foreach (array_filter(array_map('trim', explode("\n", $raw_anatomy))) as $line) { 
                    $anatomy_list[] = ["@type" => "AnatomicalStructure", "name"  => $line]; 
                }
            } else { $anatomy_list[] = ["@type" => "AnatomicalStructure", "name"  => "Skin"]; }

            // 1. Differential Diagnosis: Combine Textarea and New Relationship Field
            $diff_list = [];
            $raw_diff = get_field('condition_differential_diagnosis', $post_id);
            if ( !empty($raw_diff) ) {
                foreach (array_filter(array_map('trim', explode("\n", $raw_diff))) as $diff_item) {
                    $diff_list[] = ["@type" => "MedicalCondition", "name" => $diff_item];
                }
            }
            
            // New Relationship Logic
            $sub_types = get_field('sub_types', $post_id);
            if ( is_array($sub_types) ) {
                foreach ( $sub_types as $sub_id ) {
                    $sub_node = [
                        "@type" => "MedicalCondition",
                        "@id"   => get_permalink($sub_id) . "#condition",
                        "name"  => get_the_title($sub_id),
                        "url"   => get_permalink($sub_id),
                        "code"  => []
                    ];
                    $child_codes = get_field('codes', $sub_id);
                    if ( is_array($child_codes) ) {
                        foreach ( $child_codes as $code_row ) {
                            if ( !empty($code_row['icd-10']) ) {
                                $sub_node["code"][] = ["@type" => "MedicalCode", "codeValue" => trim($code_row['icd-10']), "codingSystem" => "ICD-10-CM"];
                            }
                            if ( !empty($code_row['snowmed']) ) {
                                $sub_node["code"][] = ["@type" => "MedicalCode", "codeValue" => trim($code_row['snowmed']), "codingSystem" => "SNOMED-CT"];
                            }
                        }
                    }
                    if ( empty($sub_node["code"]) ) unset($sub_node["code"]);
                    $diff_list[] = $sub_node;
                }
            }

            $condition_node = [
                "@type" => "MedicalCondition", 
                "@id" => get_permalink($post_id) . "#condition", 
                "name" => $clinical_name ?: get_the_title($post_id), 
                "url" => get_permalink($post_id), 
                "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"], 
                "associatedAnatomy" => $anatomy_list, 
                "epidemiology" => get_field('condition_epidemiology', $post_id) ?: null, 
                "riskFactor" => get_field('condition_risk_factor', $post_id) ?: null, 
                "differentialDiagnosis" => !empty($diff_list) ? $diff_list : null,
                "signOrSymptom" => [],
                "cause" => [],
                "possibleTreatment" => []
            ];

            // NEW FIX: Codes Repeater for the Main Condition
            $condition_codes = get_field('codes', $post_id);
            if ( is_array($condition_codes) ) {
                foreach ( $condition_codes as $code_row ) {
                    if ( !empty($code_row['icd-10']) ) {
                        $condition_node["code"][] = ["@type" => "MedicalCode", "codeValue" => trim($code_row['icd-10']), "codingSystem" => "ICD-10-CM"];
                    }
                    if ( !empty($code_row['snowmed']) ) {
                        $condition_node["code"][] = ["@type" => "MedicalCode", "codeValue" => trim($code_row['snowmed']), "codingSystem" => "SNOMED-CT"];
                    }
                }
            }

            // 2. Parent Backlink Logic
            $parent_id = get_field('parent_condition', $post_id);
            if ($parent_id) {
                $condition_node["isPartOf"] = [
                    "@type" => "MedicalCondition",
                    "@id" => get_permalink($parent_id) . "#condition",
                    "name" => get_the_title($parent_id)
                ];
            }
            
            $raw_symptoms = get_field('sign_or_symptom', $post_id);
            if (!empty($raw_symptoms)) { foreach (array_filter(array_map('trim', explode("\n", $raw_symptoms))) as $s) { $condition_node["signOrSymptom"][] = ["@type" => "MedicalSignOrSymptom", "name" => $s]; } }
            $raw_causes = get_field('cause', $post_id);
            if (!empty($raw_causes)) { foreach (array_filter(array_map('trim', explode("\n", $raw_causes))) as $c) { $condition_node["cause"][] = ["@type" => "MedicalCause", "name" => $c]; } }

            $treatments_list = get_field('possible_treatments_repeater', $post_id);
            if ( is_array($treatments_list) ) {
                foreach ($treatments_list as $t_row) {
                    $t_ids = $t_row['internal_treatment'] ?? null;
                    if ( !empty($t_ids) && is_array($t_ids) ) {
                        foreach ($t_ids as $t_item) {
                            $t_id = (is_object($t_item)) ? $t_item->ID : (is_numeric($t_item) ? $t_item : 0);
                            if ($t_id > 0) {
                                $condition_node["possibleTreatment"][] = ["@type" => "MedicalProcedure", "name" => get_the_title($t_id), "description" => get_field("procedure_description", $t_id) ?: null, "url" => get_permalink($t_id)];
                            }
                        }
                    } elseif ( !empty($t_row['internal_treatment_manual']) ) {
                        $condition_node["possibleTreatment"][] = ["@type" => "MedicalProcedure", "name" => $t_row['internal_treatment_manual']];
                    }
                }
            }

            $sources = get_field('source_of_truth', $post_id);
            if ( is_array($sources) ) { foreach ($sources as $s) { if (!empty($s['same_as'])) $condition_node["sameAs"][] = $s['same_as']; } }
            $alts = get_field('alternate_names', $post_id);
            if (!empty($alts)) $condition_node["alternateName"] = array_filter(array_map('trim', explode("\n", $alts)));

            if ( empty($condition_node["signOrSymptom"]) ) unset($condition_node["signOrSymptom"]);
            if ( empty($condition_node["cause"]) ) unset($condition_node["cause"]);
            if ( empty($condition_node["possibleTreatment"]) ) unset($condition_node["possibleTreatment"]);

            if ( is_array($linked_providers) && !empty($linked_providers) ) {
                $rev_id = $linked_providers[0]; 
                $rev_t = dacs_provider_schema_type( get_field('provider_type', $rev_id) );
                $condition_node["reviewedBy"] = ["@type" => array_values(array_unique(["Person", $rev_t])), "@id" => get_permalink($rev_id) . "#provider", "name"  => get_the_title($rev_id), "url" => get_permalink($rev_id)];
            }
            $treatment_schema["mainEntity"][] = $condition_node;
        }

// --- SHARED PROVIDERS BLOCK ---
        $treatment_schema["provider"][] = ["@type" => $entity_type, "@id" => $clinic_id];

        if ( is_array($linked_providers) ) {
            foreach ($linked_providers as $p_id) { 
                $p_t = get_field('provider_type', $p_id) ?: 'Person'; 
                $npi = get_field('provider_npi', $p_id); 
                $p_o = ["@type" => array_values(array_unique(["Person", dacs_provider_schema_type($p_t)])), "@id" => get_permalink($p_id) . "#provider", "name" => get_the_title($p_id), "url" => get_permalink($p_id), "worksFor" => ["@type" => $entity_type, "@id" => $clinic_id]]; 
                if ($p_t === 'Physician') $p_o["practicesAt"] = ["@type" => $entity_type, "@id" => $clinic_id];
                if (!empty($npi)) { 
                    if ($p_t === 'Physician') $p_o["usNPI"] = $npi; 
                    else $p_o["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $npi]; 
                } 
                $treatment_schema["provider"][] = $p_o; 
            }
        }
        dacs_print_schema( $treatment_schema );
    }

 // --- PROTECTED PROVIDER PAGES (Team) ---
    if ( is_singular('team') ) {
        $p_type = get_field('provider_type', $post_id) ?: 'Person'; 
        $npi = get_field('provider_npi', $post_id);
        $prefix = get_field('honorific_prefix', $post_id);
        $suffix = get_field('honorific_suffix', $post_id);

        $provider_entity = ["@type" => array_values(array_unique(["Person", dacs_provider_schema_type($p_type)])), "@id" => get_permalink($post_id) . "#provider", "name" => get_the_title($post_id), "jobTitle" => get_field('exact_job_title', $post_id), "url" => get_permalink($post_id), "medicalSpecialty" => get_field('medical_specialties', $post_id) ?: ["Dermatology"], "worksFor" => ["@type" => $entity_type, "@id" => $clinic_id]];

        if ( !empty($prefix) ) { $provider_entity["honorificPrefix"] = $prefix; }
        if ( !empty($suffix) ) { $provider_entity["honorificSuffix"] = $suffix; }
        
        $credentials = get_field('educational_occupational_credential', $post_id);
        if ( is_array($credentials) ) { foreach ( $credentials as $cred ) { $provider_entity["hasCredential"][] = ["@type" => "EducationalOccupationalCredential", "name" => $cred['name'] ?? '']; } }

        $edu = get_field('education_history', $post_id); 
        if ( is_array($edu) ) { foreach ($edu as $e) { if ( !empty($e['organization_name']) ) { $provider_entity["alumniOf"][] = ["@type" => "EducationalOrganization", "name" => $e['organization_name']]; } } }
        
        if ( $p_type === 'Physician' ) { $provider_entity["practicesAt"] = ["@type" => $entity_type, "@id" => $clinic_id]; }

        // v2.8: expertise, memberships, affiliations, profile links
        $knows = get_field('knows_about', $post_id);
        if ( !empty($knows) ) { $provider_entity["knowsAbout"] = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $knows)))); }

        $members = get_field('member_of', $post_id);
        if ( is_array($members) ) { foreach ($members as $m) { if ( !empty($m['name']) ) { $node = ["@type" => "Organization", "name" => $m['name']]; if ( !empty($m['same_as']) ) $node["sameAs"] = $m['same_as']; $provider_entity["memberOf"][] = $node; } } }

        $affs = get_field('affiliations', $post_id);
        if ( is_array($affs) ) { foreach ($affs as $a) { if ( !empty($a['name']) ) { $provider_entity["affiliation"][] = ["@type" => "Organization", "name" => $a['name']]; } } }

        $profiles = get_field('provider_profiles_sameas', $post_id);
        if ( is_array($profiles) ) { foreach ($profiles as $pr) { if ( !empty($pr['url']) ) { $provider_entity["sameAs"][] = $pr['url']; } } }

        if ( !empty($npi) ) { 
            if ( $p_type === 'Physician' ) { $provider_entity["usNPI"] = $npi; } 
            else { $provider_entity["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $npi]; }
        }

        // ProfilePage with the Yoast WebPage @id (merges into one page entity), about the provider.
        $profile_page = ["@type" => "ProfilePage", "@id" => get_permalink($post_id), "name" => get_the_title($post_id), "mainEntity" => ["@id" => $provider_entity["@id"]]];
        dacs_print_schema( ["@context" => "https://schema.org", "@graph" => [$profile_page, $provider_entity]] );
    }

    // --- 6. SATELLITE / GLOBAL CLINIC INJECTION ---
    if ( $current_page_location ) {
        $loc_id = trailingslashit( get_home_url() ) . dacs_location_fragment( $current_page_location['location_fragment'] ?? '', '#clinic' );
        $is_primary_row = !empty($current_page_location['is_primary']);
        $local_entity_type = $is_primary_row ? "MedicalOrganization" : "MedicalClinic";

        $location_schema = ["@context" => "https://schema.org", "@type" => $local_entity_type, "@id" => $loc_id, "name" => $current_page_location['location_name'] ?? '', "telephone" => $current_page_location['phone'] ?? '', "medicalSpecialty" => "Dermatology", "isAcceptingNewPatients" => (bool)($current_page_location['is_accepting_new_patients'] ?? true), "address" => ["@type" => "PostalAddress", "streetAddress" => $current_page_location['address']['street'] ?? '', "addressLocality" => $current_page_location['address']['city'] ?? '', "addressRegion" => $current_page_location['address']['state'] ?? '', "postalCode" => $current_page_location['address']['zip'] ?? '', "addressCountry" => "US"], "geo" => ["@type" => "GeoCoordinates", "latitude" => $current_page_location['geo']['lat'] ?? '', "longitude" => $current_page_location['geo']['long'] ?? ''], "openingHoursSpecification" => []];

        if ( !empty($current_page_location['opening_hours']) ) { foreach ($current_page_location['opening_hours'] as $h) { if (!empty($h['days'])) { $spec = ["@type" => "OpeningHoursSpecification", "dayOfWeek" => $h['days']]; if (empty($h['opens']) || empty($h['closes'])) { $spec["opens"] = "00:00"; $spec["closes"] = "00:00"; } else { $spec["opens"] = date("H:i", strtotime($h['opens'])); $spec["closes"] = date("H:i", strtotime($h['closes'])); } $location_schema["openingHoursSpecification"][] = $spec; } } }

        $holidays = $current_page_location['public_holidays'] ?? null;
        if (is_array($holidays)) { foreach ($holidays as $hol) { if (!empty($hol['date'])) { $h_spec = ["@type" => "OpeningHoursSpecification", "validFrom" => $hol['date'], "validThrough" => $hol['date']]; if (empty($hol['opens']) || empty($hol['closes'])) { $h_spec["opens"] = "00:00"; $h_spec["closes"] = "00:00"; } else { $h_spec["opens"] = date("H:i", strtotime($hol['opens'])); $h_spec["closes"] = date("H:i", strtotime($hol['closes'])); } $location_schema["openingHoursSpecification"][] = $h_spec; } } }

        $global_logo = get_field('global_clinic_logo', 'option');
        if (!empty($global_logo)) $location_schema["logo"] = $global_logo;
        $global_sameas_raw = get_field('global_clinic_sameas', 'option');
        if (!empty($global_sameas_raw)) $location_schema["sameAs"] = array_filter(array_map('trim', explode("\n", $global_sameas_raw)));
        $insurance_raw = get_field('global_clinic_insurance', 'option');
        if (!empty($insurance_raw)) $location_schema["paymentAccepted"] = array_filter(array_map('trim', explode("\n", $insurance_raw)));
        
        // v3.2: the practice's providers as Person entities linked to the clinic (employee ↔ worksFor).
        if ( $is_primary_row && apply_filters( 'dacs_schema_clinic_employees', true ) ) {
            $team_ids = get_posts( [ 'post_type' => apply_filters( 'dacs_provider_post_type', 'team' ), 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'menu_order date', 'order' => 'ASC' ] );
            foreach ( $team_ids as $t_id ) {
                $t_type = get_field( 'provider_type', $t_id ) ?: 'Person';
                $location_schema["employee"][] = [ "@type" => array_values( array_unique( [ "Person", dacs_provider_schema_type( $t_type ) ] ) ), "@id" => get_permalink( $t_id ) . "#provider", "name" => get_the_title( $t_id ), "jobTitle" => get_field( 'exact_job_title', $t_id ) ?: null, "url" => get_permalink( $t_id ), "worksFor" => [ "@id" => $loc_id ] ];
            }
        }

        if ( !$is_primary_row && $primary_location ) {
            $location_schema["parentOrganization"] = ["@type" => "MedicalOrganization", "@id" => $clinic_id, "name" => $primary_location['location_name']];
        } elseif ( $is_primary_row && ( $yoast_org = dacs_yoast_organization_id() ) ) {
            // Link the primary clinic to Yoast's Organization instead of leaving two unrelated organisations.
            $location_schema["parentOrganization"] = ["@id" => $yoast_org];
        }

        // Address: drop entirely if only the hardcoded country would remain.
        $addr = $current_page_location['address'] ?? [];
        if ( !array_filter( array_map( 'trim', [ $addr['street'] ?? '', $addr['city'] ?? '', $addr['state'] ?? '', $addr['zip'] ?? '' ] ) ) ) {
            unset( $location_schema["address"] );
        }

        // Geo: only valid when both coordinates are present.
        $geo = $current_page_location['geo'] ?? [];
        if ( trim( $geo['lat'] ?? '' ) === '' || trim( $geo['long'] ?? '' ) === '' ) {
            unset( $location_schema["geo"] );
        }

        dacs_print_schema( $location_schema );
    }
}