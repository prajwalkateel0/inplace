<?php
/**
 * Calendar Invite Helper
 * Sends calendar meeting invites via email
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send calendar invite email to attendees
 * 
 * @param array $visit - Visit details from database
 * @param array $organizer - ['name' => 'Dr. Emily Clarke', 'email' => 'emily@...']
 * @param array $attendees - [['name' => 'Jamie Smith', 'email' => 'jamie@...']]
 * @return bool Success
 */
function sendCalendarInvite($visit, $organizer, $attendees) {
    // Generate unique UID for this meeting
    $uid = 'visit-' . $visit['id'] . '@inplace-system.com';
    
    // Format dates for iCalendar (YYYYMMDDTHHmmss) 
    $startDateTime = new DateTime($visit['visit_date'] . ' ' . $visit['visit_time']);
    $endDateTime = clone $startDateTime;
    $endDateTime->modify('+' . ($visit['duration_hours'] ?? 2) . ' hours');
    
    $dtStart = $startDateTime->format('Ymd\THis');
    $dtEnd = $endDateTime->format('Ymd\THis');
    $dtStamp = gmdate('Ymd\THis\Z');
    
    // Build meeting summary
    $summary = $visit['role_title'] . ' - Placement Visit';
    
    // Build location
    $location = $visit['company_name'];
    if ($visit['type'] === 'virtual' && $visit['meeting_link']) {
        $location = 'Virtual Meeting';
    } elseif ($visit['location']) {
        $location .= ', ' . $visit['location'];
    }
    
    // Build description
    $description = "Placement Visit\\n\\n";
    $description .= "Student: " . $visit['student_name'] . "\\n";
    $description .= "Tutor: " . $organizer['name'] . "\\n";
    $description .= "Company: " . $visit['company_name'] . "\\n";
    $description .= "Role: " . $visit['role_title'] . "\\n\\n";
    
    if ($visit['type'] === 'virtual' && $visit['meeting_link']) {
        $description .= "Join Meeting: " . $visit['meeting_link'] . "\\n\\n";
    }
    
    if ($visit['notes']) {
        $description .= "Agenda:\\n" . str_replace("\n", "\\n", $visit['notes']) . "\\n";
    }
    
    // ═══════════════════════════════════════════════════════
    // Generate .ics file content (METHOD:REQUEST for invites)
    // ═══════════════════════════════════════════════════════
    $icsContent = "BEGIN:VCALENDAR\r\n";
    $icsContent .= "VERSION:2.0\r\n";
    $icsContent .= "PRODID:-//InPlace//Placement Management System//EN\r\n";
    $icsContent .= "CALSCALE:GREGORIAN\r\n";
    $icsContent .= "METHOD:REQUEST\r\n";  // ← KEY: REQUEST instead of PUBLISH
    $icsContent .= "BEGIN:VEVENT\r\n";
    $icsContent .= "UID:" . $uid . "\r\n";
    $icsContent .= "DTSTAMP:" . $dtStamp . "\r\n";
    $icsContent .= "DTSTART:" . $dtStart . "\r\n";
    $icsContent .= "DTEND:" . $dtEnd . "\r\n";
    $icsContent .= "SUMMARY:" . $summary . "\r\n";
    $icsContent .= "DESCRIPTION:" . $description . "\r\n";
    $icsContent .= "LOCATION:" . $location . "\r\n";
    
    // Set organizer (tutor)
    $icsContent .= "ORGANIZER;CN=\"" . $organizer['name'] . "\":mailto:" . $organizer['email'] . "\r\n";
    
    // Add all attendees
    foreach ($attendees as $att) {
        $icsContent .= "ATTENDEE;CN=\"" . $att['name'] . "\";ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:" . $att['email'] . "\r\n";
    }
    
    $icsContent .= "STATUS:CONFIRMED\r\n";
    $icsContent .= "SEQUENCE:0\r\n";
    $icsContent .= "PRIORITY:5\r\n";
    
    // Add reminder (1 day before)
    $icsContent .= "BEGIN:VALARM\r\n";
    $icsContent .= "TRIGGER:-P1D\r\n";
    $icsContent .= "ACTION:DISPLAY\r\n";
    $icsContent .= "DESCRIPTION:Reminder: Placement visit tomorrow\r\n";
    $icsContent .= "END:VALARM\r\n";
    
    $icsContent .= "END:VEVENT\r\n";
    $icsContent .= "END:VCALENDAR\r\n";
    
    // ═══════════════════════════════════════════════════════
    // Send email to all attendees using PHPMailer
    // ═══════════════════════════════════════════════════════
    
    require_once '../PHPMailer-master/src/Exception.php';
    require_once '../PHPMailer-master/src/PHPMailer.php';
    require_once '../PHPMailer-master/src/SMTP.php';
    
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    try {

    // ⭐ ENABLE DEBUG MODE
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = function($str, $level) {
        error_log("PHPMailer: $str");
    };
    
$mailCfg = require __DIR__ . '/../config/email_config.php';
$mail->isSMTP();
$mail->Host       = $mailCfg['smtp_host'];
$mail->SMTPAuth   = true;
$mail->Username   = $mailCfg['smtp_user'];
$mail->Password   = $mailCfg['smtp_pass'];
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port       = $mailCfg['smtp_port'];



        
        // Sender (organizer)
        $mail->setFrom($organizer['email'], $organizer['name']);
        
        // Recipients
        foreach ($attendees as $att) {
            $mail->addAddress($att['email'], $att['name']);
        }
        
        // Also send to organizer
     $mail->addAddress($organizer['email'], $organizer['name']);
        
        // Email content
        $mail->isHTML(true);
        $mail->Subject = '📅 Placement Visit: ' . $summary;
        
        // HTML body
        $htmlBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background: #0c1b33; color: white; padding: 20px; border-radius: 8px 8px 0 0;'>
                <h2 style='margin: 0;'>📅 Placement Visit Scheduled</h2>
            </div>
            
            <div style='background: #ffffff; padding: 30px; border: 1px solid #e2e6ec; border-top: none;'>
                <p style='font-size: 16px; color: #1a2332;'>
                    <strong>" . $organizer['name'] . "</strong> has scheduled a placement visit.
                </p>
                
                <div style='background: #faf8f4; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                    <table style='width: 100%; font-size: 14px;'>
                        <tr>
                            <td style='padding: 8px 0; color: #6b7a8d;'><strong>📅 Date:</strong></td>
                            <td style='padding: 8px 0; color: #1a2332;'>" . $startDateTime->format('l, F j, Y') . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #6b7a8d;'><strong>⏰ Time:</strong></td>
                            <td style='padding: 8px 0; color: #1a2332;'>" . $startDateTime->format('g:i A') . " - " . $endDateTime->format('g:i A') . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #6b7a8d;'><strong>📍 Location:</strong></td>
                            <td style='padding: 8px 0; color: #1a2332;'>" . htmlspecialchars($location) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #6b7a8d;'><strong>🏢 Company:</strong></td>
                            <td style='padding: 8px 0; color: #1a2332;'>" . htmlspecialchars($visit['company_name']) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #6b7a8d;'><strong>👤 Student:</strong></td>
                            <td style='padding: 8px 0; color: #1a2332;'>" . htmlspecialchars($visit['student_name']) . "</td>
                        </tr>
                    </table>
                </div>";
        
        if ($visit['type'] === 'virtual' && $visit['meeting_link']) {
            $htmlBody .= "
                <div style='background: #e0f2fe; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <p style='margin: 0; font-size: 14px;'><strong>🖥️ Virtual Meeting</strong></p>
                    <p style='margin: 10px 0 0;'>
                        <a href='" . htmlspecialchars($visit['meeting_link']) . "' 
                           style='color: #0369a1; text-decoration: none; word-break: break-all;'>
                            " . htmlspecialchars($visit['meeting_link']) . "
                        </a>
                    </p>
                </div>";
        }
        
        if ($visit['notes']) {
            $htmlBody .= "
                <div style='margin: 20px 0;'>
                    <p style='font-weight: 600; color: #1a2332; margin-bottom: 8px;'>📋 Agenda:</p>
                    <p style='color: #6b7a8d; line-height: 1.6;'>" . nl2br(htmlspecialchars($visit['notes'])) . "</p>
                </div>";
        }
        
        $htmlBody .= "
                <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e6ec;'>
                    <p style='font-size: 13px; color: #6b7a8d;'>
                        This calendar invite has been added to your Outlook calendar automatically.
                        <br>You can Accept or Decline from your calendar.
                    </p>
                </div>
            </div>
        </div>";
        
        $mail->Body = $htmlBody;
        
        // Plain text version
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", $htmlBody));
        
        // ═══════════════════════════════════════════════════════
        // Attach .ics file (KEY: Makes it appear in Outlook!)
        // ═══════════════════════════════════════════════════════
        $mail->addStringAttachment(
            $icsContent,
            'invite.ics',
            'base64',
            'text/calendar; method=REQUEST; charset=UTF-8'
        );
        
        // Send email
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Calendar invite failed: " . $mail->ErrorInfo);
        return false;
    }
}
?>