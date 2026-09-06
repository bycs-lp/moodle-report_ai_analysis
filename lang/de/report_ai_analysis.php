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
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['acceptaiterms'] = 'KI-Nutzungsbedingungen prüfen und akzeptieren';
$string['activities'] = 'Aktivitäten';
$string['add_template'] = 'Neue Vorlage hinzufügen';
$string['ai_analysis:create'] = 'KI-Analyseberichte erstellen';
$string['ai_analysis:delete'] = 'KI-Analyseberichte löschen';
$string['ai_analysis:manageall'] = 'KI-Analyseberichte anderer Nutzer verwalten (zusätzliches Aktionsrecht erforderlich)';
$string['ai_analysis:rerun'] = 'KI-Analyseberichte erneut ausführen';
$string['ai_analysis:view'] = 'KI-Analyseberichte anzeigen';
$string['ai_analysis:viewall'] = 'KI-Analyseberichte anderer Nutzer auch ohne Kursfreigabe anzeigen';
$string['ai_analysis:viewrawdata'] = 'Rohdaten in Berichten anzeigen';
$string['aimodel'] = 'KI-Modell';
$string['aiunavailable'] = 'Die KI-Analyse ist derzeit nicht verfügbar. Prüfen Sie die KI-Konfiguration, die Nutzungsbedingungen und das verbleibende Kontingent.';
$string['allusers'] = 'Alle Nutzer';
$string['analysis_mode'] = 'Analysemodus';
$string['analysis_mode_aggregated'] = 'Aggregiert (alle Teilnehmer)';
$string['analysis_mode_help'] = 'Wählen Sie den Analysemodus:<ul><li><strong>Individuell:</strong> Erstellt separate Analysen für jeden ausgewählten Teilnehmer. Ideal zur Identifikation individueller Lernschwierigkeiten.</li><li><strong>Aggregiert:</strong> Erstellt eine zusammenfassende Analyse über alle ausgewählten Teilnehmer hinweg. Ideal für Anforderungsanalysen und Gesamtüberblicke.</li></ul>';
$string['analysis_mode_individual'] = 'Individuell (pro Teilnehmer)';
$string['analysis_result'] = 'Analyseergebnis';
$string['analysis_truncated'] = 'Diese Analyse ist unvollständig: Quelldaten oder der endgültige Prompt wurden zur Einhaltung einer Sicherheitsgrenze gekürzt. Betrachten Sie das Ergebnis nicht als vollständige Beurteilung.';
$string['analysisqueued'] = 'Die Analyse wurde zur Hintergrundverarbeitung in die Warteschlange gestellt';
$string['cachedef_providers'] = 'Cache für Datenquellenanbieter';
$string['cachedef_role_names'] = 'Namen der Kursrollen';
$string['cachedef_scope_parse'] = 'Eingelesene Analysebereiche';
$string['cancel'] = 'Abbrechen';
$string['cancelwarning'] = 'Ein Abbruch verhindert die weitere Verarbeitung und verwirft später eintreffende Ergebnisse. Eine bereits an die KI gesendete Anfrage kann nicht zurückgerufen werden.';
$string['cannotdeleteothersreports'] = 'Sie können keine Berichte löschen, die von anderen Nutzern erstellt wurden';
$string['cannoteditrunningreport'] = 'Ein laufender Bericht kann nicht bearbeitet werden';
$string['cannotexportreport'] = 'Nur abgeschlossene oder fehlgeschlagene Berichte können exportiert werden.';
$string['cannotrerunreport'] = 'Dieser Bericht kann nicht erneut ausgeführt werden. Nur abgeschlossene, fehlgeschlagene oder abgebrochene Berichte können erneut ausgeführt werden.';
$string['confirm_delete_template'] = 'Vorlage löschen';
$string['confirm_delete_template_text'] = 'Möchten Sie die Vorlage "{$a}" wirklich löschen?';
$string['confirmdelete'] = 'Möchten Sie den Bericht "{$a}" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.';
$string['confirmdeletion'] = 'Löschen bestätigen';
$string['context_system'] = 'System';
$string['coresystem'] = 'System';
$string['coursename'] = 'Kurs';
$string['courses'] = 'Kurse';
$string['createanalysis'] = 'Neue Analyse erstellen';
$string['created'] = 'Erstellt';
$string['dataprivacy_notice'] = 'Die zulässigen Beiträge der ausgewählten Teilnehmer und Ihr Prompt werden über den KI-Manager an den konfigurierten KI-Dienst gesendet. Das Deaktivieren der Rohdatenspeicherung betrifft nur die Quellenkopie dieses Berichts, nicht die Übermittlung oder die Protokollierung im KI-Manager. Prüfen Sie vor dem Fortfahren die KI-Nutzungsbedingungen und die Datenschutzvorgaben Ihrer Organisation.';
$string['datasource'] = 'Datenquelle';
$string['datasource_help'] = 'Wählen Sie zugängliche Foren oder KI-Chat-Blöcke in diesem Kurs aus. Eine leere Quellenauswahl umfasst alle unterstützten, für Sie zugänglichen Quellen, jedoch keine anderen Aktivitätstypen.';
$string['delete'] = 'Löschen';
$string['deletereport'] = 'Bericht löschen';
$string['disable'] = 'Deaktivieren';
$string['duration'] = 'Dauer';
$string['edit'] = 'Bearbeiten';
$string['edit_template'] = 'Vorlage bearbeiten';
$string['editreport'] = 'Bericht bearbeiten';
$string['enable'] = 'Aktivieren';
$string['enable_markdown_conversion'] = 'Markdown-Rendering aktivieren';
$string['enable_markdown_conversion_desc'] = 'Prompts und ältere Markdown-Ergebnisse formatiert anzeigen. Neue Ergebnisse des KI-Managers enthalten bereits HTML und werden als HTML bereinigt. Bei deaktivierter Einstellung werden Ergebnisse als Klartext ohne HTML-Tags angezeigt.';
$string['error_access_revoked'] = 'Der Kurszugang oder ein erforderliches Analyserecht wurde entzogen. Die Analyse kann nicht weiter ausgeführt werden.';
$string['error_ai_chat_not_available'] = 'Block ai_chat ist nicht installiert oder nicht aktiviert';
$string['error_ai_request'] = 'Die KI-Anfrage ist fehlgeschlagen. Versuchen Sie es später erneut oder kontaktieren Sie die Administration.';
$string['error_api_connection_error'] = 'Verbindung zum KI-Service konnte nicht hergestellt werden';
$string['error_api_timeout'] = 'API-Anfrage hat das Zeitlimit überschritten';
$string['error_contextmismatch'] = 'Bericht gehört nicht zum angegebenen Kurs-Kontext';
$string['error_deleting_template'] = 'Fehler beim Löschen der Vorlage';
$string['error_empty_response'] = 'KI-Service hat eine leere Antwort zurückgegeben';
$string['error_forum_not_available'] = 'Modul Forum ist nicht installiert oder nicht aktiviert';
$string['error_invalid_limit'] = 'Geben Sie eine positive ganze Zahl innerhalb des in der Einstellungsbeschreibung angegebenen Bereichs ein.';
$string['error_invalid_timerange'] = 'Die Endzeit darf nicht vor der Startzeit liegen. Jede der beiden Grenzen kann deaktiviert bleiben.';
$string['error_no_data'] = 'Keine Konversationsdaten zur Analyse gefunden';
$string['error_prompt_too_long'] = 'Prompt ist zu lang für den KI-Service';
$string['error_prompt_too_short'] = 'Prompt muss mindestens 10 Zeichen lang sein';
$string['error_purposenotconfigured'] = 'Der KI-Zweck "singleprompt" ist nicht konfiguriert. Bitte kontaktieren Sie Ihren Administrator.';
$string['error_rate_limit'] = 'Ratenlimit des KI-Services erreicht';
$string['error_saving_template'] = 'Fehler beim Speichern der Vorlage';
$string['error_source_failed'] = 'Eine ausgewählte Datenquelle konnte nicht eingelesen werden. Die Analyse wurde beendet, statt ein nicht gekennzeichnetes Teilergebnis zu erstellen.';
$string['error_source_forbidden'] = 'Sie dürfen eine oder mehrere ausgewählte Quellen oder Teilnehmer nicht mehr analysieren.';
$string['error_task_changed'] = 'Dieser Analyselauf ist nicht mehr aktuell. Sein Ergebnis ersetzt den aktuellen Bericht nicht.';
$string['error_terms_not_accepted'] = 'Sie haben die KI-Nutzungsbedingungen noch nicht akzeptiert. Akzeptieren Sie diese, bevor Sie den Bericht erneut ausführen.';
$string['error_title_too_long'] = 'Titel darf maximal 255 Zeichen lang sein';
$string['error_title_too_short'] = 'Titel muss mindestens 3 Zeichen lang sein';
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
$string['exportedat'] = 'Exportiert am';
$string['exportedfrom'] = 'Exportiert aus';
$string['exporthtml'] = 'Als HTML exportieren';
$string['exportjson'] = 'Als JSON exportieren';
$string['groups'] = 'Gruppen';
$string['includesubcategories'] = 'Unterkategorien einbeziehen';
$string['individual_mode_warning'] = 'Achtung: Im individuellen Modus wird für jeden Teilnehmer eine separate Analyse erstellt. Dies kann bei vielen Teilnehmern zu hohem Token-Verbrauch führen.';
$string['invalidformat'] = 'Ungültiges Exportformat angegeben';
$string['manage_templates'] = 'Prompt-Vorlagen verwalten';
$string['max_records_per_analysis'] = 'Maximale Datensätze pro Analyse';
$string['max_records_per_analysis_desc'] = 'Insgesamt 1 bis 10000 Datensätze über alle ausgewählten Quellen und Teilnehmer. Jeder Forumsbeitrag oder protokollierte KI-Chat-Aufruf zählt als ein Datensatz. Das Erreichen einer Erfassungs- oder Promptgrenze wird als Kürzung angezeigt.';
$string['metadata'] = 'Analyse-Metadaten';
$string['newanalysis'] = 'Neue Analyse';
$string['no_templates'] = 'Noch keine Vorlagen konfiguriert';
$string['nopermission'] = 'Sie haben keine Berechtigung, diese Aktion auszuführen';
$string['order'] = 'Reihenfolge';
$string['participant'] = 'Teilnehmer';
$string['participants'] = 'Teilnehmer';
$string['pluginname'] = 'KI-Konversationsanalyse';
$string['privacy:export:legacydata'] = 'Dieser ältere Bericht lässt sich nicht zuverlässig nach Personen trennen. Gemeinsame Quelldaten und Ergebnisse werden nicht ausgegeben; die gespeicherte vorsichtige Teilnehmerzuordnung wird für Auskunft und Löschung verwendet.';
$string['privacy:export:reports'] = 'KI-Analyseberichte';
$string['privacy:export:shareddata'] = 'Enthalten sind nur Ihnen zugeordnete Quelldaten und individuelle Ergebnisse. Zusammengefasste Ergebnisse, gemeinsame Quelldaten, Daten anderer Teilnehmer und technische Diagnosen werden nicht ausgegeben.';
$string['privacy:metadata:ai_service'] = 'Der konfigurierte externe KI-Dienst erhält die Analyseanweisungen und zulässigen Quellbeiträge über den KI-Manager. Seine Aufbewahrungsregeln sind unabhängig von den Speichereinstellungen dieses Berichts.';
$string['privacy:metadata:ai_service:prompt'] = 'Die an den KI-Dienst gesendeten Systemanweisungen und der Analyse-Prompt des Berichtserstellers';
$string['privacy:metadata:ai_service:source_data'] = 'Zulässige Forumsbeiträge oder KI-Chat-Anfrage-/Antwortpaare, gegebenenfalls einschließlich der Identität ihrer Autoren';
$string['privacy:metadata:local_ai_manager'] = 'Der KI-Manager verarbeitet und protokolliert Analyseanfragen unabhängig von der Rohdatenspeicherung des Berichts. Verknüpfte Berichtsprotokolle werden über seine Datenbereinigungs-API anonymisiert; Nutzungsstatistiken bleiben erhalten.';
$string['privacy:metadata:local_ai_manager:contextid'] = 'Der Kurskontext, in dem die Analyseanfrage gestellt wurde';
$string['privacy:metadata:local_ai_manager:itemid'] = 'Die Berichts-ID zur Verknüpfung mit den Anfrageprotokollen des KI-Managers';
$string['privacy:metadata:local_ai_manager:promptcompletion'] = 'Die vom KI-Manager protokollierte KI-Antwort';
$string['privacy:metadata:local_ai_manager:prompttext'] = 'Die vollständige Analyseanfrage einschließlich Anweisungen und ausgewählter Quellbeiträge';
$string['privacy:metadata:local_ai_manager:requestoptions'] = 'Anfrageoptionen einschließlich der Berichtskennung';
$string['privacy:metadata:local_ai_manager:timecreated'] = 'Der Zeitpunkt der Protokollierung der Analyseanfrage';
$string['privacy:metadata:local_ai_manager:userid'] = 'Der ausführende Nutzer, nicht zwingend der Autor der analysierten Beiträge';
$string['privacy:metadata:report_ai_analysis_reports'] = 'Speichert von Nutzern erstellte KI-Analyseberichte';
$string['privacy:metadata:report_ai_analysis_reports:action'] = 'Die Aktion, deren Berechtigungen für den eingereihten Lauf erneut geprüft werden müssen';
$string['privacy:metadata:report_ai_analysis_reports:ai_model_name'] = 'Das für die Analyse verwendete KI-Modell';
$string['privacy:metadata:report_ai_analysis_reports:ai_result'] = 'Das KI-generierte Analyseergebnis';
$string['privacy:metadata:report_ai_analysis_reports:contextid'] = 'Der Kurskontext des Berichts';
$string['privacy:metadata:report_ai_analysis_reports:error_code'] = 'Der klassifizierte Fehlercode einer fehlgeschlagenen Analyse';
$string['privacy:metadata:report_ai_analysis_reports:error_details'] = 'Die für eine fehlgeschlagene Analyse gespeicherten technischen Diagnoseinformationen';
$string['privacy:metadata:report_ai_analysis_reports:error_message'] = 'Die nutzerfreundliche Beschreibung einer fehlgeschlagenen Analyse';
$string['privacy:metadata:report_ai_analysis_reports:execution_time'] = 'Die Analysedauer in ganzen Sekunden';
$string['privacy:metadata:report_ai_analysis_reports:legacydata'] = 'Kennzeichnet ältere Inhalte, die nicht zuverlässig nach Personen getrennt werden können';
$string['privacy:metadata:report_ai_analysis_reports:prompt'] = 'Der vom Nutzer bereitgestellte Analyse-Prompt';
$string['privacy:metadata:report_ai_analysis_reports:raw_data'] = 'Die analysierten Rohdaten der Konversation';
$string['privacy:metadata:report_ai_analysis_reports:resultformat'] = 'Das Format zur Interpretation des gespeicherten KI-Ergebnisses';
$string['privacy:metadata:report_ai_analysis_reports:retry_count'] = 'Die Anzahl automatischer Wiederholungen in diesem Lauf';
$string['privacy:metadata:report_ai_analysis_reports:runversion'] = 'Die Generationsnummer, die das Überschreiben neuerer Daten durch veraltete Analyseergebnisse verhindert';
$string['privacy:metadata:report_ai_analysis_reports:scope_details'] = 'Kurs, Analysemodus und Auswahl von Quellen, Teilnehmern, Rollen, Gruppen und Zeitraum';
$string['privacy:metadata:report_ai_analysis_reports:status'] = 'Der aktuelle Verarbeitungszustand des Berichts';
$string['privacy:metadata:report_ai_analysis_reports:timecompleted'] = 'Der Zeitpunkt des Verarbeitungsendes';
$string['privacy:metadata:report_ai_analysis_reports:timecreated'] = 'Der Zeitpunkt, zu dem der Bericht erstellt wurde';
$string['privacy:metadata:report_ai_analysis_reports:timemodified'] = 'Der Zeitpunkt der letzten Berichtsänderung';
$string['privacy:metadata:report_ai_analysis_reports:title'] = 'Der Titel des Analyseberichts';
$string['privacy:metadata:report_ai_analysis_reports:token_usage'] = 'Die protokollierte Token-Nutzung der Analyse';
$string['privacy:metadata:report_ai_analysis_reports:truncated'] = 'Kennzeichnet eine durch Sicherheitsgrenzen gekürzte Quellenerfassung oder einen gekürzten endgültigen Prompt';
$string['privacy:metadata:report_ai_analysis_reports:userid'] = 'Die ID des Nutzers, der den Bericht erstellt hat';
$string['privacy:metadata:report_ai_analysis_users'] = 'Ordnet Berichte den Personen zu, deren Beiträge enthalten waren, gegebenenfalls mit getrennt zuordenbaren Daten';
$string['privacy:metadata:report_ai_analysis_users:ai_result'] = 'Das individuelle KI-Ergebnis für diesen Teilnehmer; bei einer aggregierten Analyse kein Ergebnis';
$string['privacy:metadata:report_ai_analysis_users:reportid'] = 'Der Bericht, der Daten über den Teilnehmer enthält';
$string['privacy:metadata:report_ai_analysis_users:source_data'] = 'Nur die einbezogenen Quellbeiträge dieses Teilnehmers, sofern die Rohdatenspeicherung des Berichts aktiviert ist';
$string['privacy:metadata:report_ai_analysis_users:userid'] = 'Der Teilnehmer, dessen Beiträge enthalten waren, oder eine vorsichtig zugeordnete betroffene Person aus Altdaten';
$string['prompt'] = 'Analyse-Prompt';
$string['prompt_help'] = 'Beschreiben Sie, was die KI über die Konversationsdaten analysieren soll.';
$string['prompt_preview'] = 'Vorschau';
$string['prompt_templates'] = 'Prompt-Vorlagen';
$string['prompt_templates_desc'] = 'Wiederverwendbare Prompts unter Prompt-Vorlagen verwalten erstellen, bearbeiten, aktivieren und sortieren. Vorlagen werden separat gespeichert, nicht als zeilenbasierte Einstellung.';
$string['purposeplacedescription'] = 'Kursberichte über zulässige Forumsbeiträge und KI-Chat-Einträge, gemeinsam oder in getrennten Anfragen pro Teilnehmer analysiert.';
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
$string['select_template'] = 'Beispielprompt auswählen...';
$string['selectstudents'] = 'Bestimmte Schüler auswählen';
$string['share_reports_in_course'] = 'Berichte im Kurs teilen';
$string['share_reports_in_course_desc'] = 'Nutzern mit Anzeigerecht erlauben, Berichte anderer Nutzer im selben Kurs zu lesen. Die Freigabe erlaubt niemals Bearbeiten, erneutes Ausführen, Abbrechen oder Löschen. Für Rohdaten bleibt das gesonderte Rohdatenrecht erforderlich.';
$string['show_raw_data'] = 'Rohdaten anzeigen';
$string['sortorder'] = 'Sortierreihenfolge';
$string['sources'] = 'Datenquellen';
$string['status'] = 'Status';
$string['status_cancelled'] = 'Abgebrochen';
$string['status_completed'] = 'Abgeschlossen';
$string['status_failed'] = 'Fehlgeschlagen';
$string['status_pending'] = 'Ausstehend';
$string['status_running'] = 'Wird ausgeführt';
$string['store_raw_data'] = 'Rohdaten speichern';
$string['store_raw_data_desc'] = 'Eine begrenzte Quellenkopie im Bericht und in den Teilnehmerzeilen speichern. Das Deaktivieren verhindert weder die Übermittlung an den KI-Dienst noch die Ergebnisspeicherung oder die vollständige Anfrageprotokollierung im KI-Manager.';
$string['students'] = 'Schüler';
$string['system_prompt'] = 'System-Prompt';
$string['system_prompt_default'] = 'Sie sind ein Bildungsdatenanalyst. Analysieren Sie die bereitgestellten Konversationsdaten und liefern Sie Erkenntnisse.';
$string['system_prompt_desc'] = 'Der System-Prompt, der vor dem Nutzer-Prompt an die KI gesendet wird';
$string['task_process_analysis'] = 'KI-Analysebericht verarbeiten';
$string['template_created'] = 'Vorlage erfolgreich erstellt';
$string['template_deleted'] = 'Vorlage erfolgreich gelöscht';
$string['template_enabled'] = 'Aktiviert';
$string['template_enabled_help'] = 'Deaktivierte Vorlagen werden im Formular nicht angezeigt, aber nicht gelöscht';
$string['template_prompt'] = 'Prompt-Text';
$string['template_prompt_help'] = 'Der Prompt-Text, der in das Analyseformular eingefügt wird, wenn diese Vorlage ausgewählt wird';
$string['template_title'] = 'Vorlagen-Titel';
$string['template_title_help'] = 'Kurzer beschreibender Titel für den Button oder die Dropdown-Option';
$string['template_updated'] = 'Vorlage erfolgreich aktualisiert';
$string['timecompleted'] = 'Abgeschlossen';
$string['timeend'] = 'Endzeit';
$string['timerange_help'] = 'Beide Grenzen sind optional und einschließlich; eine deaktivierte Grenze ist offen. Beim KI-Chat werden nur Anfrageeinträge innerhalb des Zeitraums einbezogen. Bei Foren wählt der Zeitraum Diskussionen mit einem sichtbaren Beitrag eines ausgewählten Autors im Zeitraum aus; weitere zulässige Beiträge ausgewählter Autoren in diesen Diskussionen dürfen älter oder neuer sein.';
$string['timestart'] = 'Startzeit';
$string['title'] = 'Titel';
$string['title_help'] = 'Optionaler Titel für diese Analyse. Wenn leer, werden die ersten 80 Zeichen des Prompts verwendet.';
$string['tokenusage'] = 'Token-Nutzung';
$string['truncate_raw_data_length'] = 'Rohdatenlänge begrenzen';
$string['truncate_raw_data_length_desc'] = '1 bis 500000 Zeichen pro gespeicherter Quellenkopie (Standard: 50000). Dieses Speicherlimit verringert nicht die Protokollierung im KI-Manager. Der endgültige KI-Prompt ist einschließlich Anweisungen separat auf 1000000 Zeichen begrenzt.';
$string['unknown'] = 'Unbekannt';
$string['use_template'] = 'Vorlage verwenden';
$string['view'] = 'Anzeigen';
