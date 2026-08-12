<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Repairs content whose sub-content library versions were left behind by a
 * batch content upgrade run with a plugin version that did not send library
 * semantics to the upgrade process (regression from HFP-4358, fixed by #633).
 *
 * Symptom: "The version of the H5P library X used in this content is not valid.
 * Content contains X 1.18, but it should be X 1.22." and the activity renders
 * empty. The parameters are NOT lost - only the rendered/filtered copy is
 * stripped - so bumping the stale sub-content version strings restores it.
 *
 * Run a dry run first (default), review, then re-run with --fix.
 *
 * @package    mod_hvp
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../locallib.php');

list($options, $unrecognized) = cli_get_params([
    'help'      => false,
    'fix'       => false,
    'contentid' => '',
    'courseid'  => '',
    'since'     => '',
    'upgraded'  => false,
    'backup'    => '',
    'verbose'   => false,
], [
    'h' => 'help',
    'v' => 'verbose',
]);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help']) {
    echo <<<EOT
Repair stale sub-content library versions in H5P activities.

Options:
  -h, --help          Print this help.
      --fix           Actually write the repaired parameters. Without this the
                      script only reports (dry run).
      --contentid=1,2 Limit to these {hvp}.id values.
      --courseid=1,2  Limit to activities in these courses. Accepts course ids
                      or course shortnames.
      --upgraded      Limit to content that has a 'content upgrade' entry in
                      {hvp_events} (i.e. content touched by a batch upgrade).
      --since=TIME    With --upgraded, only events at/after TIME. Accepts a unix
                      timestamp or anything strtotime() understands.
      --backup=FILE   With --fix, write the original json_content of every
                      changed row to FILE (JSON). Defaults to
                      \$CFG->dataroot/hvp_subcontent_backup_<timestamp>.json
  -v, --verbose       List every single change, not just a per-activity summary.

Examples:
  php mod/hvp/cli/fix_subcontent_versions.php
  php mod/hvp/cli/fix_subcontent_versions.php --upgraded --since="2026-06-25"
  php mod/hvp/cli/fix_subcontent_versions.php --courseid=42 -v
  php mod/hvp/cli/fix_subcontent_versions.php --courseid=42 --fix
  php mod/hvp/cli/fix_subcontent_versions.php --upgraded --fix

EOT;
    exit(0);
}

$core = \mod_hvp\framework::instance();
$syscontext = \context_system::instance();

/**
 * Cache of decoded semantics, keyed by uber name.
 */
$semanticscache = [];

/**
 * Load (and cache) semantics for a library uber name, e.g. "H5P.Column 1.22".
 *
 * @param string $ubername
 * @return array|null Array of semantics field objects, or null if unavailable.
 */
function hvp_fix_semantics($ubername) {
    global $core, $semanticscache;

    if (array_key_exists($ubername, $semanticscache)) {
        return $semanticscache[$ubername];
    }

    $lib = \H5PCore::libraryFromString($ubername);
    $semantics = null;
    if ($lib) {
        // @codingStandardsIgnoreLine
        $semantics = $core->loadLibrarySemantics($lib['machineName'], $lib['majorVersion'], $lib['minorVersion']);
    }
    if (!is_array($semantics)) {
        $semantics = null;
    }

    $semanticscache[$ubername] = $semantics;
    return $semantics;
}

/**
 * Cache of "does this library ship upgrades.js" lookups.
 */
$upgradescriptcache = [];

/**
 * Whether the given library ships an upgrades.js, meaning the version bump we
 * are about to make may also have needed parameter migration hooks.
 *
 * @param string $ubername
 * @return bool
 */
function hvp_fix_has_upgrade_script($ubername) {
    global $syscontext, $upgradescriptcache;

    if (array_key_exists($ubername, $upgradescriptcache)) {
        return $upgradescriptcache[$ubername];
    }

    $lib = \H5PCore::libraryFromString($ubername);
    $exists = false;
    if ($lib) {
        $folder = \H5PCore::libraryToFolderName([
            'machineName'  => $lib['machineName'],
            'majorVersion' => $lib['majorVersion'],
            'minorVersion' => $lib['minorVersion'],
        ]);
        $exists = \mod_hvp\file_storage::fileExists($syscontext->id, 'libraries', '/' . $folder . '/', 'upgrades.js');
    }

    $upgradescriptcache[$ubername] = $exists;
    return $exists;
}

/**
 * Normalise a semantics library field's options to a flat list of uber names.
 *
 * @param object $field
 * @return string[]
 */
function hvp_fix_option_names($field) {
    if (!isset($field->options) || !is_array($field->options)) {
        return [];
    }
    $names = [];
    foreach ($field->options as $option) {
        if (is_object($option) && isset($option->name)) {
            $names[] = $option->name;
        } else if (is_string($option)) {
            $names[] = $option;
        }
    }
    return $names;
}

/**
 * Walk a group of semantics fields, mirroring H5PContentValidator::validateGroup.
 *
 * @param mixed $value Params for the group (by reference, may be patched).
 * @param array $fields Semantics fields.
 * @param bool $flatten Single field groups are flattened in the editor.
 * @param string $path Human readable position, for reporting.
 * @param array $report Collected findings (by reference).
 */
function hvp_fix_walk_group(&$value, $fields, $flatten, $path, &$report) {
    if (!is_array($fields) || empty($fields)) {
        return;
    }

    if (count($fields) === 1 && $flatten) {
        hvp_fix_walk_field($value, $fields[0], $path, $report);
        return;
    }

    if (!is_object($value)) {
        return;
    }

    foreach ($fields as $field) {
        if (!isset($field->name) || !isset($field->type)) {
            continue;
        }
        $name = $field->name;
        if (!property_exists($value, $name) || $value->$name === null) {
            continue;
        }
        hvp_fix_walk_field($value->$name, $field, $path . '/' . $name, $report);
    }
}

/**
 * Walk a single semantics field.
 *
 * @param mixed $value Params for the field (by reference, may be patched).
 * @param object $field Semantics field.
 * @param string $path Human readable position, for reporting.
 * @param array $report Collected findings (by reference).
 */
function hvp_fix_walk_field(&$value, $field, $path, &$report) {
    if (!isset($field->type)) {
        return;
    }

    switch ($field->type) {
        case 'library':
            hvp_fix_walk_library($value, $field, $path, $report);
            break;

        case 'group':
            if (isset($field->fields)) {
                $issubcontent = isset($field->isSubContent) && $field->isSubContent === true;
                hvp_fix_walk_group($value, $field->fields, !$issubcontent, $path, $report);
            }
            break;

        case 'list':
            if (is_array($value) && isset($field->field)) {
                foreach ($value as $i => &$item) {
                    if ($item === null) {
                        continue;
                    }
                    hvp_fix_walk_field($item, $field->field, $path . '[' . $i . ']', $report);
                }
                unset($item);
            }
            break;
    }
}

/**
 * Inspect a sub-content reference, bump a stale version if we safely can, then
 * recurse into its parameters.
 *
 * @param mixed $value Sub-content object: {library, params, subContentId}.
 * @param object $field Semantics library field declaring the allowed options.
 * @param string $path Human readable position, for reporting.
 * @param array $report Collected findings (by reference).
 */
function hvp_fix_walk_library(&$value, $field, $path, &$report) {
    if (!is_object($value) || !isset($value->library) || !is_string($value->library)) {
        return;
    }

    $current = $value->library;
    $options = hvp_fix_option_names($field);

    if (!empty($options) && !in_array($current, $options, true)) {
        $lib = \H5PCore::libraryFromString($current);
        if ($lib) {
            $target = null;
            foreach ($options as $option) {
                $optlib = \H5PCore::libraryFromString($option);
                if (!$optlib || $optlib['machineName'] !== $lib['machineName']) {
                    continue;
                }
                // Only same major version is a guaranteed compatible parameter
                // format, and only upwards. Anything else needs the real
                // upgrade process or a restore.
                if ($optlib['majorVersion'] == $lib['majorVersion'] &&
                        $optlib['minorVersion'] > $lib['minorVersion']) {
                    $target = $option;
                }
            }

            if ($target !== null) {
                $value->library = $target;
                $report['fixes'][] = [
                    'path'    => $path,
                    'from'    => $current,
                    'to'      => $target,
                    'hooks'   => hvp_fix_has_upgrade_script($target),
                ];
            } else {
                $report['manual'][] = [
                    'path'    => $path,
                    'found'   => $current,
                    'allowed' => implode(', ', $options),
                ];
            }
        }
    }

    $semantics = hvp_fix_semantics($value->library);
    if ($semantics === null) {
        $report['missing'][] = ['path' => $path, 'library' => $value->library];
        return;
    }

    if (isset($value->params)) {
        // A library's own parameters are never flattened, cf. validateLibrary().
        hvp_fix_walk_group($value->params, $semantics, false, $path . ' (' . $value->library . ')', $report);
    }
}

// Build the list of content to inspect.
$where = [];
$params = [];

if ($options['contentid'] !== '') {
    $ids = array_filter(array_map('intval', explode(',', $options['contentid'])));
    if (empty($ids)) {
        cli_error('--contentid did not contain any usable ids.');
    }
    list($insql, $inparams) = $DB->get_in_or_equal($ids);
    $where[] = "c.id $insql";
    $params = array_merge($params, $inparams);
}

if ($options['courseid'] !== '') {
    $courseids = [];
    foreach (explode(',', $options['courseid']) as $needle) {
        $needle = trim($needle);
        if ($needle === '') {
            continue;
        }
        if (is_numeric($needle)) {
            $course = $DB->get_record('course', ['id' => (int)$needle], 'id, shortname, fullname');
        } else {
            $course = $DB->get_record('course', ['shortname' => $needle], 'id, shortname, fullname');
        }
        if (!$course) {
            cli_error("No course matches '{$needle}'.");
        }
        $courseids[] = $course->id;
        cli_writeln("Limiting to course {$course->id}: {$course->fullname} ({$course->shortname})");
    }
    if (empty($courseids)) {
        cli_error('--courseid did not contain any usable ids or shortnames.');
    }
    list($insql, $inparams) = $DB->get_in_or_equal($courseids);
    $where[] = "c.course $insql";
    $params = array_merge($params, $inparams);
}

if ($options['upgraded']) {
    $eventwhere = "e.type = ? AND e.sub_type = ?";
    $eventparams = ['content', 'upgrade'];
    if ($options['since'] !== '') {
        $since = is_numeric($options['since']) ? (int)$options['since'] : strtotime($options['since']);
        if (!$since) {
            cli_error('Could not parse --since. Use a unix timestamp or e.g. "2026-06-25".');
        }
        $eventwhere .= " AND e.created_at >= ?";
        $eventparams[] = $since;
        cli_writeln('Limiting to content upgraded at/after ' . userdate($since));
    }
    $where[] = "EXISTS (SELECT 1 FROM {hvp_events} e WHERE e.content_id = c.id AND $eventwhere)";
    $params = array_merge($params, $eventparams);
}

$sql = "SELECT c.id, c.name, c.course, c.json_content,
               l.machine_name, l.major_version, l.minor_version
          FROM {hvp} c
          JOIN {hvp_libraries} l ON l.id = c.main_library_id";
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY c.id ASC';

$contents = $DB->get_records_sql($sql, $params);
cli_writeln('Inspecting ' . count($contents) . ' H5P activities' .
    ($options['fix'] ? '' : ' (dry run - nothing will be written)'));
cli_writeln(str_repeat('-', 78));

$backup = [];
$totalfixed = 0;
$totalfixes = 0;
$totalmanual = 0;
$hookwarnings = [];

foreach ($contents as $content) {
    $decoded = json_decode($content->json_content);
    if ($decoded === null) {
        cli_writeln("!! [{$content->id}] {$content->name}: json_content is not valid JSON, skipping.");
        continue;
    }

    $mainlib = "{$content->machine_name} {$content->major_version}.{$content->minor_version}";
    $semantics = hvp_fix_semantics($mainlib);
    if ($semantics === null) {
        cli_writeln("!! [{$content->id}] {$content->name}: no semantics for main library {$mainlib}, skipping.");
        continue;
    }

    $report = ['fixes' => [], 'manual' => [], 'missing' => []];
    hvp_fix_walk_group($decoded, $semantics, false, $mainlib, $report);

    if (empty($report['fixes']) && empty($report['manual']) && empty($report['missing'])) {
        continue;
    }

    $course = $DB->get_field('course', 'shortname', ['id' => $content->course]);
    cli_writeln("[{$content->id}] {$content->name} ({$course}) - {$mainlib}");

    if (!empty($report['fixes'])) {
        $grouped = [];
        foreach ($report['fixes'] as $fix) {
            $key = $fix['from'] . ' -> ' . $fix['to'];
            $grouped[$key] = ($grouped[$key] ?? 0) + 1;
            if ($fix['hooks']) {
                $hookwarnings[$key] = true;
            }
            if ($options['verbose']) {
                cli_writeln("      at {$fix['path']}: {$fix['from']} -> {$fix['to']}");
            }
        }
        foreach ($grouped as $key => $count) {
            cli_writeln("   fix  {$key} (x{$count})");
        }
        $totalfixes += count($report['fixes']);
    }

    foreach ($report['manual'] as $manual) {
        cli_writeln("   MANUAL {$manual['found']} is not upgradable in place; semantics allow: {$manual['allowed']}");
        cli_writeln("          at {$manual['path']}");
        $totalmanual++;
    }

    foreach ($report['missing'] as $missing) {
        cli_writeln("   MISSING library {$missing['library']} is not installed, cannot inspect its parameters");
        cli_writeln("           at {$missing['path']}");
    }

    if (!empty($report['fixes'])) {
        $totalfixed++;
        if ($options['fix']) {
            $backup[$content->id] = $content->json_content;
            $DB->update_record('hvp', (object) [
                'id'           => $content->id,
                'json_content' => json_encode($decoded, JSON_PRESERVE_ZERO_FRACTION),
                // Force H5P to re-filter parameters and rebuild dependencies.
                'filtered'     => '',
            ]);
        }
    }
}

cli_writeln(str_repeat('-', 78));
cli_writeln("Activities with repairable sub-content versions: {$totalfixed} ({$totalfixes} references)");
if ($totalmanual) {
    cli_writeln("References needing manual attention: {$totalmanual}");
}

if (!empty($hookwarnings)) {
    cli_writeln('');
    cli_writeln('These libraries ship an upgrades.js, so the version bump may also have');
    cli_writeln('needed parameter migration hooks that this script cannot run. Spot check');
    cli_writeln('a few affected activities:');
    foreach (array_keys($hookwarnings) as $key) {
        cli_writeln("  - {$key}");
    }
}

if ($options['fix'] && !empty($backup)) {
    $backupfile = $options['backup'] !== ''
        ? $options['backup']
        : $CFG->dataroot . '/hvp_subcontent_backup_' . date('Ymd_His') . '.json';
    if (file_put_contents($backupfile, json_encode($backup)) === false) {
        cli_error("Repairs were written but the backup file {$backupfile} could not be created.");
    }
    cli_writeln('');
    cli_writeln("Original json_content of the {$totalfixed} changed rows saved to:");
    cli_writeln("  {$backupfile}");
} else if (!$options['fix']) {
    cli_writeln('');
    cli_writeln('Dry run only. Re-run with --fix to write these changes.');
}

exit(0);
