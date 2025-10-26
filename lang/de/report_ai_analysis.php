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
 * German language strings for report_ai_analysis.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activities'] = 'Aktivitäten';
$string['ai_analysis:create'] = 'KI-Analyseberichte erstellen';
$string['ai_analysis:delete'] = 'KI-Analyseberichte löschen';
$string['ai_analysis:rerun'] = 'KI-Analyseberichte erneut ausführen';
$string['ai_analysis:view'] = 'KI-Analyseberichte anzeigen';
$string['ai_analysis:viewrawdata'] = 'Rohdaten in Berichten anzeigen';
$string['aimodel'] = 'KI-Modell';
$string['allusers'] = 'Alle Nutzer';
$string['analysis_mode'] = 'Analysemodus';
$string['analysis_mode_aggregated'] = 'Aggregiert (alle Teilnehmer)';
$string['analysis_mode_help'] = 'Wählen Sie den Analysemodus:<ul><li><strong>Individuell:</strong> Erstellt separate Analysen für jeden ausgewählten Teilnehmer. Ideal zur Identifikation individueller Lernschwierigkeiten.</li><li><strong>Aggregiert:</strong> Erstellt eine zusammenfassende Analyse über alle ausgewählten Teilnehmer hinweg. Ideal für Anforderungsanalysen und Gesamtüberblicke.</li></ul>';
$string['analysis_mode_individual'] = 'Individuell (pro Teilnehmer)';
$string['analysis_result'] = 'Analyseergebnis';
$string['analysisqueued'] = 'Die Analyse wurde zur Hintergrundverarbeitung in die Warteschlange gestellt';
$string['cancel'] = 'Abbrechen';
$string['cannotdeleteothersreports'] = 'Sie können keine Berichte löschen, die von anderen Nutzern erstellt wurden';
$string['cannoteditrunningreport'] = 'Ein laufender Bericht kann nicht bearbeitet werden';
$string['cannotrerunreport'] = 'Dieser Bericht kann nicht erneut ausgeführt werden. Nur abgeschlossene, fehlgeschlagene oder abgebrochene Berichte können erneut ausgeführt werden.';
$string['confirmdelete'] = 'Möchten Sie den Bericht "{$a}" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.';
$string['confirmdeletion'] = 'Löschen bestätigen';
$string['context_system'] = 'System';
$string['coresystem'] = 'System';
$string['coursename'] = 'Kurs';
$string['courses'] = 'Kurse';
$string['createanalysis'] = 'Neue Analyse erstellen';
$string['created'] = 'Erstellt';
$string['datasource'] = 'Datenquelle';
$string['datasource_help'] = 'Wählen Sie aus, welche Datenquellen (Aktivitäten, Blöcke usw.) analysiert werden sollen';
$string['delete'] = 'Löschen';
$string['deletereport'] = 'Bericht löschen';
$string['duration'] = 'Dauer';
$string['edit'] = 'Bearbeiten';
$string['editreport'] = 'Bericht bearbeiten';
$string['enable_markdown_conversion'] = 'Markdown-Rendering aktivieren';
$string['enable_markdown_conversion_desc'] = 'Markdown in KI-Antworten in HTML konvertieren';
$string['error_ai_chat_not_available'] = 'Block ai_chat ist nicht installiert oder nicht aktiviert';
$string['error_ai_request'] = 'KI-Anfrage fehlgeschlagen: {$a}';
$string['error_api_connection_error'] = 'Verbindung zum KI-Service konnte nicht hergestellt werden';
$string['error_api_timeout'] = 'API-Anfrage hat das Zeitlimit überschritten';
$string['error_contextmismatch'] = 'Bericht gehört nicht zum angegebenen Kurs-Kontext';
$string['error_empty_response'] = 'KI-Service hat eine leere Antwort zurückgegeben';
$string['error_forum_not_available'] = 'Modul Forum ist nicht installiert oder nicht aktiviert';
$string['error_no_data'] = 'Keine Konversationsdaten zur Analyse gefunden';
$string['error_prompt_too_long'] = 'Prompt ist zu lang für den KI-Service';
$string['error_prompt_too_short'] = 'Prompt muss mindestens 10 Zeichen lang sein';
$string['error_purposenotconfigured'] = 'Der KI-Zweck "singleprompt" ist nicht konfiguriert. Bitte kontaktieren Sie Ihren Administrator.';
$string['error_rate_limit'] = 'Ratenlimit des KI-Services erreicht';
$string['error_unknown'] = 'Ein unbekannter Fehler ist aufgetreten';
$string['errorrerunningreport'] = 'Fehler beim erneuten Ausführen des Berichts';
$string['eventreportdeleted'] = 'KI-Analysebericht gelöscht';
$string['export'] = 'Exportieren';
$string['export_context_id'] = 'Kontext-ID';
$string['export_conversation_thread'] = 'Konversations-Thread';
$string['export_conversations_header'] = 'KI-CHAT-KONVERSATIONEN';
$string['export_course'] = 'Kurs';
$string['export_created'] = 'Erstellt';
$string['export_discussion'] = 'Diskussion';
$string['export_discussions_header'] = 'FORUMSDISKUSSIONEN';
$string['export_forum'] = 'Forum';
$string['export_messages'] = 'Nachrichten';
$string['export_modified'] = 'Zuletzt geändert';
$string['export_posts'] = 'Beiträge';
$string['export_started_by'] = 'Gestartet von';
$string['export_total_conversations'] = 'Konversationen gesamt';
$string['export_total_discussions'] = 'Diskussionen gesamt';
$string['export_truncated'] = '[gekürzt]';
$string['export_user'] = 'Nutzer';
$string['exporthtml'] = 'Als HTML exportieren';
$string['exportjson'] = 'Als JSON exportieren';
$string['groups'] = 'Gruppen';
$string['includesubcategories'] = 'Unterkategorien einbeziehen';
$string['individual_mode_warning'] = 'Achtung: Im individuellen Modus wird für jeden Teilnehmer eine separate Analyse erstellt. Dies kann bei vielen Teilnehmern zu hohem Token-Verbrauch führen.';
$string['invalidformat'] = 'Ungültiges Exportformat angegeben';
$string['max_records_per_analysis'] = 'Maximale Datensätze pro Analyse';
$string['max_records_per_analysis_desc'] = 'Maximale Anzahl von Konversationsdatensätzen, die in eine Analyse einbezogen werden';
$string['metadata'] = 'Analyse-Metadaten';
$string['newanalysis'] = 'Neue Analyse';
$string['nopermission'] = 'Sie haben keine Berechtigung, diese Aktion auszuführen';
$string['participant'] = 'Teilnehmer';
$string['participants'] = 'Teilnehmer';
$string['pluginname'] = 'KI-Konversationsanalyse';
$string['privacy:metadata:report_ai_analysis_reports'] = 'Speichert von Nutzern erstellte KI-Analyseberichte';
$string['privacy:metadata:report_ai_analysis_reports:ai_result'] = 'Das KI-generierte Analyseergebnis';
$string['privacy:metadata:report_ai_analysis_reports:prompt'] = 'Der vom Nutzer bereitgestellte Analyse-Prompt';
$string['privacy:metadata:report_ai_analysis_reports:raw_data'] = 'Die analysierten Rohdaten der Konversation';
$string['privacy:metadata:report_ai_analysis_reports:timecreated'] = 'Der Zeitpunkt, zu dem der Bericht erstellt wurde';
$string['privacy:metadata:report_ai_analysis_reports:title'] = 'Der Titel des Analyseberichts';
$string['privacy:metadata:report_ai_analysis_reports:userid'] = 'Die ID des Nutzers, der den Bericht erstellt hat';
$string['prompt'] = 'Analyse-Prompt';
$string['prompt_help'] = 'Beschreiben Sie, was die KI über die Konversationsdaten analysieren soll.';
$string['prompt_templates'] = 'Prompt-Vorlagen';
$string['prompt_templates_desc'] = 'Vordefinierte Prompt-Vorlagen (eine pro Zeile, Format: "Vorlagenname|Prompt-Text")';
$string['raw_data'] = 'Konversations-Rohdaten';
$string['report_actions'] = 'Aktionen';
$string['report_created'] = 'Erstellt';
$string['report_creator'] = 'Ersteller';
$string['report_scope'] = 'Umfang';
$string['report_status'] = 'Status';
$string['report_title'] = 'Titel';
$string['reportcancelled'] = 'Bericht erfolgreich abgebrochen';
$string['reportdeleted'] = 'Bericht erfolgreich gelöscht';
$string['reportrerunsuccess'] = 'Bericht wurde zur erneuten Verarbeitung in die Warteschlange gestellt';
$string['reportupdated'] = 'Bericht erfolgreich aktualisiert';
$string['reportupdatedandqueued'] = 'Bericht aktualisiert und zur erneuten Verarbeitung in die Warteschlange gestellt';
$string['rerun'] = 'Erneut ausführen';
$string['rerunreport'] = 'Bericht erneut ausführen';
$string['rerunreportconfirm'] = 'Möchten Sie den Bericht "{$a}" wirklich erneut ausführen? Dies setzt die aktuellen Ergebnisse zurück und verarbeitet die Analyse erneut.';
$string['retries'] = 'Wiederholungen';
$string['retry_on_failure'] = 'Bei Fehler wiederholen';
$string['retry_on_failure_desc'] = 'Anzahl automatischer Wiederholungen bei vorübergehenden Fehlern (0 = keine Wiederholungen, max. 3)';
$string['roles'] = 'Rollen';
$string['scope'] = 'Umfang';
$string['select_participants'] = 'Teilnehmer auswählen';
$string['select_participants_help'] = 'Wählen Sie aus, welche Kursteilnehmer in die Analyse einbezogen werden sollen. Sie können nach Kursrollen filtern.';
$string['select_roles'] = 'Nach Rollen filtern';
$string['select_roles_help'] = 'Wählen Sie aus, welche Kursrollen einbezogen werden sollen. Leer lassen, um alle Rollen einzubeziehen.';
$string['selectstudents'] = 'Bestimmte Schüler auswählen';
$string['share_reports_in_course'] = 'Berichte im Kurs teilen';
$string['share_reports_in_course_desc'] = 'Lehrenden erlauben, Berichte voneinander im selben Kurs zu sehen';
$string['show_raw_data'] = 'Rohdaten anzeigen';
$string['sources'] = 'Datenquellen';
$string['status'] = 'Status';
$string['status_cancelled'] = 'Abgebrochen';
$string['status_completed'] = 'Abgeschlossen';
$string['status_failed'] = 'Fehlgeschlagen';
$string['status_pending'] = 'Ausstehend';
$string['status_running'] = 'Wird ausgeführt';
$string['store_raw_data'] = 'Rohdaten speichern';
$string['store_raw_data_desc'] = 'Die Rohdaten der Konversation in der Datenbank speichern (kann deaktiviert werden, um Speicherplatz zu sparen)';
$string['students'] = 'Schüler';
$string['system_prompt'] = 'System-Prompt';
$string['system_prompt_default'] = 'Sie sind ein Bildungsdatenanalyst. Analysieren Sie die bereitgestellten Konversationsdaten und liefern Sie Erkenntnisse.';
$string['system_prompt_desc'] = 'Der System-Prompt, der vor dem Nutzer-Prompt an die KI gesendet wird';
$string['task_process_analysis'] = 'KI-Analysebericht verarbeiten';
$string['timecompleted'] = 'Abgeschlossen';
$string['timeend'] = 'Endzeit';
$string['timeout_seconds'] = 'Anfrage-Timeout';
$string['timeout_seconds_desc'] = 'Timeout für KI-Anfragen in Sekunden';
$string['timestart'] = 'Startzeit';
$string['title'] = 'Titel';
$string['title_help'] = 'Optionaler Titel für diese Analyse. Wenn leer, werden die ersten 80 Zeichen des Prompts verwendet.';
$string['tokenusage'] = 'Token-Nutzung';
$string['truncate_raw_data_length'] = 'Rohdatenlänge begrenzen';
$string['truncate_raw_data_length_desc'] = 'Maximale Länge der zu speichernden Rohdaten (Zeichen)';
$string['unknown'] = 'Unbekannt';
$string['view'] = 'Anzeigen';
