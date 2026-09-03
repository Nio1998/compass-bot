<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * Elenco dei tipi di documento selezionabili nella modale di /gps-valida, con
 * la mappatura verso i file sorgente specifici da usare per il retrieval
 * mirato — sia lato slide teoriche del corso, sia lato documenti di
 * riferimento reali (progetto esempio in gps_validation_refs).
 *
 * Un tipo senza file mappati in una delle due liste userà semplicemente la
 * ricerca generica su tutta quella collection (comportamento equivalente a
 * "nessun filtro").
 */
class DocumentTypes
{
    /**
     * @return array<string, string> value => etichetta mostrata nella dropdown
     */
    public static function options(): array
    {
        return [
            'business_case'    => 'Business Case',
            'sow'              => 'SOW (Statement of Work)',
            'scope_statement'  => 'Scope Statement',
            'wbs'              => 'WBS',
            'risk_plan'        => 'Risk Management Plan',
            'agenda'           => 'Agenda',
            'minuta'           => 'Minuta',
            'status_report'    => 'Status Report / EVM',
            'time_management'  => 'Time Management (Gantt/PERT)',
            'project_charter'  => 'Project Charter',
            'stakeholder_reg'  => 'Stakeholder Register',
            'team_contract'    => 'Team Contract',
            'config_mgmt_plan' => 'Configuration Management Plan',
            'raci'             => 'Matrice RACI / Responsabilità',
            'lesson_learned'   => 'Lesson Learned',
            'scrum'            => 'Scrum (Sprint Backlog/Planning/Review)',
            'financial_analysis' => 'Financial Analysis',
        ];
    }

    /** @return string[] Nomi file in gps_slides pertinenti a questo tipo, o [] per nessun filtro. */
    public static function slideSources(string $type): array
    {
        return match ($type) {
            'business_case' => ['business_case_financials.pdf'],
            'sow' => ['SOW_Tirocinio.pdf'],
            'scope_statement' => ['SopeStatement.pdf'],
            'wbs' => ['02.wbs.pdf', 'Template WBS Dictionary.pdf', '08 - Planning WBS PERT GANTT-2-1 [Salvato automaticamente].pdf'],
            'risk_plan' => ['ITPM_11_Risk.pdf'],
            'agenda' => ['TemplateAgenda_Definitivo-1.pdf'],
            'minuta' => ['TemplateMinuta_Definitivo.pdf'],
            'status_report' => ['04.projcomm  -  StatusReportTools.pdf', 'Project_mgmt_slides_EVM_finale.pdf', 'Kids_SER_EVM.pdf', 'Note on Earned Value Management.pdf', 'earned_value.pdf', 'progress-reporting.pdf'],
            'time_management' => ['08 - Planning WBS PERT GANTT-2-1 [Salvato automaticamente].pdf'],
            'lesson_learned' => ['Retrospective.pdf', 'Retrospective efficace.pdf'],
            'scrum' => ['Intro-Scrum.pdf'],
            default => [],
        };
    }

    /** @return string[] Nomi file in gps_validation_refs pertinenti a questo tipo, o [] per nessun filtro. */
    public static function referenceSources(string $type): array
    {
        return match ($type) {
            'business_case' => ['2024_CO4_Esistere_BC.pdf'],
            'sow' => ['2024_CO4_Esistere_SOW.pdf'],
            'scope_statement' => ['2024_CO4_Esistere_SS.pdf'],
            'wbs' => ['2024_CO4_WBS.pdf', '2024_CO4_WBS_PM.pdf'],
            'risk_plan' => ['Esistere_Risk_Management_Plan.pdf'],
            'agenda' => [
                'C04_Agenda_1_KickOffMeeting.pdf', 'C04_Agenda_2_TeamBuildingMeeting.pdf',
                'C04_Agenda_Meeting_3.pdf', 'C04_Agenda_Meeting_4.pdf', 'C04_Agenda_Meeting_5.pdf',
                'C04_Agenda_Meeting_6.pdf', 'C04_Agenda_Meeting_7.pdf', 'C04_Agenda_Meeting_8.pdf',
                'C04_Agenda_Meeting_9.pdf', 'C04_Agenda_Meeting_11.pdf', 'CO4_Agenda_Meeting_10.pdf',
            ],
            'minuta' => [
                'C04_Minuta_1_KickOffMeeting.pdf', 'C04_Minuta_2_TeamBuildingMeeting.pdf',
                'C04_Minuta_Meeting_3.pdf', 'CO4_Minuta_Meeting_4.pdf', 'C04_Minuta_Meeting_5.pdf',
                'C04_Minuta_Meeting_6.pdf', 'C04_Minuta_Meeting_7.pdf', 'C04_Minuta_Meeting_8.pdf',
                'C04_Minuta_Meeting_9.pdf', 'C04_Minuta_Meeting_10.pdf', 'C04_Minuta_Meeting_11.pdf',
            ],
            'status_report' => ['StatusReport_I.pdf', 'StatusReport_II.pdf', 'StatusReport_III.pdf', 'StatusReport_Finale.pdf'],
            'time_management' => ['EsistereGANTT.pdf', 'EsistereNetworkDiagram.pdf', 'Time Management.docx.pdf'],
            'project_charter' => ['C2024_CO4_Esistere_PC.pdf'],
            'stakeholder_reg' => ['2024_CO4_SR.pdf'],
            'team_contract' => ['2024_CO4_TC.pdf'],
            'config_mgmt_plan' => ['2024_CO4_Esistere_Configuration_Management_Plan.pdf'],
            'raci' => ['Responsabilità.pdf'],
            'lesson_learned' => ['2024_C04_LL.pdf'],
            'scrum' => ['2024_C04_SRR_I.pdf', '2024_C04_SRR_II.pdf', '2024_CO4_SPB.pdf'],
            default => [],
        };
    }

    public static function label(string $type): string
    {
        return self::options()[$type] ?? $type;
    }
}
