<?php
/**
 * Scheduled scans via WP Cron with email reports.
 *
 * @package MediaCleanup
 */

namespace MediaCleanup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages WP Cron-based scheduled media scans and email reports.
 */
class Scheduler {

    /**
     * WP Cron hook name.
     */
    const CRON_HOOK = 'jemc_scheduled_scan';

    /**
     * Option key for schedule settings.
     */
    const OPTION_KEY = 'jemc_schedule_settings';

    /**
     * Valid frequency values.
     */
    const VALID_FREQUENCIES = array( 'daily', 'weekly', 'monthly' );

    /**
     * Constructor -- register the cron action.
     */
    public function __construct() {
        add_action( self::CRON_HOOK, array( $this, 'run_scheduled_scan' ) );
        add_filter( 'cron_schedules', array( $this, 'add_monthly_schedule' ) );
    }

    /**
     * Add a monthly recurrence schedule to WP Cron.
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified schedules.
     */
    public function add_monthly_schedule( array $schedules ): array {
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __( 'Once Monthly', 'media-cleanup' ),
            );
        }

        return $schedules;
    }

    /**
     * Get current schedule settings.
     *
     * @return array {
     *     @type bool   $enabled     Whether scheduled scans are enabled.
     *     @type string $frequency   Scan frequency: 'daily', 'weekly', or 'monthly'.
     *     @type string $email       Email address for reports.
     *     @type string $last_run    Date/time of last scan run.
     *     @type array  $last_result Summary of last scan results.
     * }
     */
    public function get_settings(): array {
        $defaults = array(
            'enabled'     => false,
            'frequency'   => 'weekly',
            'email'       => get_option( 'admin_email', '' ),
            'last_run'    => '',
            'last_result' => array(),
        );

        $settings = get_option( self::OPTION_KEY, array() );

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Update schedule settings and manage the cron event.
     *
     * @param array $settings Settings to update. Supports:
     *   'enabled'   => bool,
     *   'frequency' => 'daily'|'weekly'|'monthly',
     *   'email'     => string email address.
     * @return void
     */
    public function update_settings( array $settings ): void {
        $current = $this->get_settings();

        // Sanitize and merge incoming settings.
        if ( isset( $settings['enabled'] ) ) {
            $current['enabled'] = (bool) $settings['enabled'];
        }

        if ( isset( $settings['frequency'] ) && in_array( $settings['frequency'], self::VALID_FREQUENCIES, true ) ) {
            $current['frequency'] = $settings['frequency'];
        }

        if ( isset( $settings['email'] ) ) {
            $email = sanitize_email( $settings['email'] );
            if ( is_email( $email ) ) {
                $current['email'] = $email;
            }
        }

        update_option( self::OPTION_KEY, $current, false );

        // Manage the cron event.
        $this->unschedule_cron();

        if ( $current['enabled'] ) {
            $this->schedule_cron( $current['frequency'] );
        }
    }

    /**
     * Schedule the cron event.
     *
     * @param string $frequency The recurrence: 'daily', 'weekly', or 'monthly'.
     * @return void
     */
    private function schedule_cron( string $frequency ): void {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        $recurrence = $frequency;

        // Calculate the first run time (next day at 3:00 AM site time).
        $timezone   = wp_timezone();
        $now        = new \DateTime( 'now', $timezone );
        $next_run   = new \DateTime( 'tomorrow 03:00', $timezone );
        $timestamp  = $next_run->getTimestamp();

        wp_schedule_event( $timestamp, $recurrence, self::CRON_HOOK );
    }

    /**
     * Unschedule all instances of the cron event.
     *
     * @return void
     */
    private function unschedule_cron(): void {
        wp_unschedule_hook( self::CRON_HOOK );
    }

    /**
     * Run the scheduled scan and send an email report.
     *
     * @return void
     */
    public function run_scheduled_scan(): void {
        $settings = $this->get_settings();

        // Run the unused media scan.
        $scanner    = new Scanner();
        $all_ids    = $scanner->get_all_attachment_ids();
        $used_ids   = $scanner->get_used_attachment_ids();
        $used_set   = array_flip( $used_ids );
        $unused_ids = array();
        $total_size = 0;

        foreach ( $all_ids as $id ) {
            if ( ! isset( $used_set[ $id ] ) ) {
                $unused_ids[] = $id;
                $file_path    = get_attached_file( $id );
                if ( $file_path && file_exists( $file_path ) ) {
                    $size = filesize( $file_path );
                    if ( $size ) {
                        $total_size += $size;
                    }
                }
            }
        }

        // Run the duplicates scan.
        $duplicates      = new Duplicates();
        $duplicate_groups = $duplicates->find_duplicates( true );
        $duplicate_summary = $duplicates->get_summary( $duplicate_groups );

        // Run broken links scan.
        $broken_links        = new Broken_Links();
        $broken_links_results = $broken_links->find_broken_links( true );
        $broken_links_summary = $broken_links->get_summary( $broken_links_results );

        // Build result data.
        $result = array(
            'scan_date'           => current_time( 'mysql' ),
            'total_media'         => count( $all_ids ),
            'unused_count'        => count( $unused_ids ),
            'unused_size'         => $total_size,
            'unused_size_hr'      => size_format( $total_size ),
            'duplicate_groups'    => $duplicate_summary['total_groups'],
            'duplicate_wasted'    => $duplicate_summary['total_wasted_size'],
            'duplicate_wasted_hr' => $duplicate_summary['total_wasted_size_hr'],
            'broken_links_count'  => $broken_links_summary['total_broken_urls'],
        );

        // Update settings with last run info.
        $settings['last_run']    = $result['scan_date'];
        $settings['last_result'] = $result;
        update_option( self::OPTION_KEY, $settings, false );

        // Send the email report.
        if ( ! empty( $settings['email'] ) && is_email( $settings['email'] ) ) {
            $this->send_report_email( $settings['email'], $result );
        }
    }

    /**
     * Send the scan report via email.
     *
     * @param string $to    Recipient email address.
     * @param array  $data  Scan result data.
     * @return bool Whether the email was sent.
     */
    private function send_report_email( string $to, array $data ): bool {
        $site_name = get_bloginfo( 'name' );
        $subject   = sprintf(
            /* translators: %s: Site name. */
            __( '[%s] Media Cleanup Scan Report', 'media-cleanup' ),
            $site_name
        );

        $html = $this->generate_report_html( $data );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $site_name, get_option( 'admin_email' ) ),
        );

        return wp_mail( $to, $subject, $html, $headers );
    }

    /**
     * Generate the HTML content for the email report.
     *
     * @param array $data Scan result data.
     * @return string HTML email content.
     */
    private function generate_report_html( array $data ): string {
        $site_name  = esc_html( get_bloginfo( 'name' ) );
        $scan_date  = esc_html( $data['scan_date'] );
        $admin_url  = esc_url( admin_url( 'tools.php?page=media-cleanup' ) );

        $unused_count  = (int) $data['unused_count'];
        $unused_size   = esc_html( $data['unused_size_hr'] );
        $dup_groups    = (int) $data['duplicate_groups'];
        $dup_wasted    = esc_html( $data['duplicate_wasted_hr'] );
        $broken_count  = (int) $data['broken_links_count'];
        $total_media   = (int) $data['total_media'];

        $has_issues = ( $unused_count > 0 || $dup_groups > 0 || $broken_count > 0 );
        $status_color = $has_issues ? '#e74c3c' : '#27ae60';
        $status_text  = $has_issues
            ? __( 'Issues Found', 'media-cleanup' )
            : __( 'All Clean', 'media-cleanup' );

        $html = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:20px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.1);">

<!-- Header -->
<tr><td style="background:#0073aa;padding:24px 32px;color:#ffffff;">
<h1 style="margin:0;font-size:22px;font-weight:600;">' . esc_html__( 'Media Cleanup Report', 'media-cleanup' ) . '</h1>
<p style="margin:8px 0 0;font-size:14px;opacity:0.9;">' . $site_name . ' &mdash; ' . $scan_date . '</p>
</td></tr>

<!-- Status Banner -->
<tr><td style="padding:20px 32px 0;">
<div style="background:' . $status_color . ';color:#ffffff;padding:12px 20px;border-radius:6px;font-size:15px;font-weight:600;text-align:center;">
' . esc_html( $status_text ) . '
</div>
</td></tr>

<!-- Summary -->
<tr><td style="padding:24px 32px;">
<h2 style="margin:0 0 16px;font-size:16px;color:#333;">' . esc_html__( 'Scan Summary', 'media-cleanup' ) . '</h2>
<table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#555;">
<tr>
<td style="padding:10px 0;border-bottom:1px solid #eee;">' . esc_html__( 'Total Media Files', 'media-cleanup' ) . '</td>
<td style="padding:10px 0;border-bottom:1px solid #eee;text-align:right;font-weight:600;color:#333;">' . number_format_i18n( $total_media ) . '</td>
</tr>
<tr>
<td style="padding:10px 0;border-bottom:1px solid #eee;">' . esc_html__( 'Unused Media Files', 'media-cleanup' ) . '</td>
<td style="padding:10px 0;border-bottom:1px solid #eee;text-align:right;font-weight:600;color:' . ( $unused_count > 0 ? '#e74c3c' : '#333' ) . ';">' . number_format_i18n( $unused_count ) . ' (' . $unused_size . ')</td>
</tr>
<tr>
<td style="padding:10px 0;border-bottom:1px solid #eee;">' . esc_html__( 'Duplicate Groups', 'media-cleanup' ) . '</td>
<td style="padding:10px 0;border-bottom:1px solid #eee;text-align:right;font-weight:600;color:' . ( $dup_groups > 0 ? '#e67e22' : '#333' ) . ';">' . number_format_i18n( $dup_groups ) . ' (' . $dup_wasted . ' ' . esc_html__( 'wasted', 'media-cleanup' ) . ')</td>
</tr>
<tr>
<td style="padding:10px 0;">' . esc_html__( 'Broken Media Links', 'media-cleanup' ) . '</td>
<td style="padding:10px 0;text-align:right;font-weight:600;color:' . ( $broken_count > 0 ? '#e74c3c' : '#333' ) . ';">' . number_format_i18n( $broken_count ) . '</td>
</tr>
</table>
</td></tr>

<!-- CTA -->
<tr><td style="padding:0 32px 32px;text-align:center;">
<a href="' . $admin_url . '" style="display:inline-block;background:#0073aa;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:5px;font-size:14px;font-weight:600;">' . esc_html__( 'Review in Dashboard', 'media-cleanup' ) . '</a>
</td></tr>

<!-- Footer -->
<tr><td style="background:#f8f8f8;padding:16px 32px;text-align:center;font-size:12px;color:#999;border-top:1px solid #eee;">
' . sprintf(
            /* translators: %s: Plugin name. */
            esc_html__( 'This report was generated by %s.', 'media-cleanup' ),
            'Media Cleanup'
        ) . '
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>';

        return $html;
    }

    /**
     * Get the timestamp of the next scheduled scan.
     *
     * @return int|false Timestamp or false if not scheduled.
     */
    public function get_next_scheduled(): int|false {
        return wp_next_scheduled( self::CRON_HOOK );
    }

    /**
     * Unschedule all events on plugin deactivation.
     *
     * @return void
     */
    public function deactivate(): void {
        $this->unschedule_cron();
    }
}
