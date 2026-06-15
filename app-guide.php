<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

// any logged-in user can access this
requireAuth();

$userName = authName();
$userRole = ucfirst(authRole());
$today    = date('d F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>InPlace — Application Guide</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  /* ── Base ── */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'DM Sans', sans-serif;
    font-size: 10.5pt;
    color: #1a202c;
    background: #f7f6f3;
    line-height: 1.65;
  }

  /* ── Screen-only toolbar ── */
  .toolbar {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    background: #0c1b33;
    padding: 0.875rem 2rem;
    display: flex; align-items: center; justify-content: space-between;
    box-shadow: 0 2px 12px rgba(0,0,0,0.25);
  }
  .toolbar-brand { color: #fff; font-family: 'Playfair Display', serif; font-size: 1.1rem; }
  .toolbar-brand span { color: #f59e0b; }
  .toolbar-actions { display: flex; gap: 0.75rem; align-items: center; }
  .btn-download {
    background: #f59e0b; color: #0c1b33; border: none; border-radius: 8px;
    padding: 0.6rem 1.4rem; font-family: 'DM Sans', sans-serif;
    font-weight: 600; font-size: 0.875rem; cursor: pointer;
    display: flex; align-items: center; gap: 0.5rem;
    text-decoration: none;
    transition: background 0.2s;
  }
  .btn-download:hover { background: #d97706; }
  .btn-back {
    color: rgba(255,255,255,0.7); text-decoration: none;
    font-size: 0.875rem; display: flex; align-items: center; gap: 0.4rem;
  }
  .btn-back:hover { color: #fff; }

  /* ── Document wrapper ── */
  .doc-wrap {
    max-width: 820px;
    margin: 5rem auto 3rem;
    padding: 0 1.5rem;
  }

  /* ── Cover page ── */
  .cover {
    background: #0c1b33;
    border-radius: 16px;
    padding: 4rem 3.5rem;
    margin-bottom: 2.5rem;
    color: #fff;
    position: relative;
    overflow: hidden;
  }
  .cover::before {
    content: '';
    position: absolute; top: -60px; right: -60px;
    width: 280px; height: 280px;
    border-radius: 50%;
    background: rgba(245,158,11,0.12);
  }
  .cover-label {
    font-size: 0.75rem; letter-spacing: 0.12em; text-transform: uppercase;
    color: #f59e0b; font-weight: 600; margin-bottom: 1rem;
  }
  .cover-title {
    font-family: 'Playfair Display', serif;
    font-size: 2.6rem; line-height: 1.2;
    margin-bottom: 0.5rem;
  }
  .cover-title span { color: #f59e0b; }
  .cover-subtitle {
    font-size: 1rem; color: rgba(255,255,255,0.65);
    margin-bottom: 2.5rem;
  }
  .cover-meta {
    display: flex; gap: 2.5rem; flex-wrap: wrap;
    border-top: 1px solid rgba(255,255,255,0.12);
    padding-top: 1.5rem;
    font-size: 0.85rem; color: rgba(255,255,255,0.55);
  }
  .cover-meta strong { display: block; color: #fff; font-size: 0.9rem; }

  /* ── Table of contents ── */
  .toc {
    background: #fff;
    border-radius: 12px;
    padding: 2rem 2.5rem;
    margin-bottom: 2.5rem;
    border: 1px solid #e2e8f0;
  }
  .toc h2 {
    font-family: 'Playfair Display', serif;
    font-size: 1.15rem; color: #0c1b33;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #f59e0b;
    display: inline-block;
  }
  .toc-list { list-style: none; }
  .toc-list li {
    display: flex; justify-content: space-between; align-items: baseline;
    padding: 0.35rem 0;
    border-bottom: 1px dotted #e2e8f0;
  }
  .toc-list li:last-child { border-bottom: none; }
  .toc-list a { color: #0c1b33; text-decoration: none; font-weight: 500; }
  .toc-list a:hover { color: #f59e0b; }
  .toc-num { font-family: 'DM Mono', monospace; font-size: 0.8rem; color: #94a3b8; }

  /* ── Sections ── */
  .section {
    background: #fff;
    border-radius: 12px;
    padding: 2.5rem;
    margin-bottom: 2rem;
    border: 1px solid #e2e8f0;
  }
  .section-header {
    display: flex; align-items: center; gap: 1rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #f1f5f9;
  }
  .section-num {
    background: #0c1b33; color: #fff;
    font-family: 'DM Mono', monospace; font-size: 0.85rem;
    width: 2rem; height: 2rem; border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }
  .section h2 {
    font-family: 'Playfair Display', serif;
    font-size: 1.35rem; color: #0c1b33;
  }
  .section h3 {
    font-size: 0.95rem; font-weight: 600; color: #0c1b33;
    margin: 1.25rem 0 0.5rem;
  }
  .section p { color: #374151; margin-bottom: 0.75rem; }
  .section ul, .section ol {
    padding-left: 1.5rem; color: #374151; margin-bottom: 0.75rem;
  }
  .section li { margin-bottom: 0.35rem; }

  /* ── Role cards ── */
  .role-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem; margin-top: 1rem;
  }
  .role-card {
    border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 1.25rem; position: relative; overflow: hidden;
  }
  .role-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  }
  .role-card.student::before { background: #3b82f6; }
  .role-card.tutor::before   { background: #8b5cf6; }
  .role-card.provider::before{ background: #059669; }
  .role-card.admin::before   { background: #f59e0b; }
  .role-card.director::before{ background: #ef4444; }
  .role-icon { font-size: 1.75rem; margin-bottom: 0.5rem; }
  .role-card h4 { font-size: 0.9rem; font-weight: 600; color: #0c1b33; margin-bottom: 0.4rem; }
  .role-card p  { font-size: 0.82rem; color: #64748b; margin: 0; }

  /* ── Workflow steps ── */
  .workflow { display: flex; flex-direction: column; gap: 0; margin: 1rem 0; }
  .step {
    display: flex; gap: 1rem; align-items: flex-start;
    position: relative; padding-bottom: 1.25rem;
  }
  .step:last-child { padding-bottom: 0; }
  .step-line {
    position: absolute; left: 1rem; top: 2rem; bottom: 0;
    width: 2px; background: #e2e8f0;
  }
  .step:last-child .step-line { display: none; }
  .step-num {
    width: 2rem; height: 2rem; border-radius: 50%;
    background: #0c1b33; color: #fff;
    font-size: 0.8rem; font-weight: 600;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; position: relative; z-index: 1;
  }
  .step-body h4 { font-size: 0.9rem; font-weight: 600; color: #0c1b33; margin-bottom: 0.2rem; }
  .step-body p  { font-size: 0.85rem; color: #64748b; margin: 0; }

  /* ── Feature table ── */
  .feat-table { width: 100%; border-collapse: collapse; margin-top: 0.75rem; }
  .feat-table th {
    text-align: left; padding: 0.6rem 0.875rem;
    background: #f8fafc; color: #475569;
    font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
    border-bottom: 2px solid #e2e8f0;
  }
  .feat-table td {
    padding: 0.7rem 0.875rem; border-bottom: 1px solid #f1f5f9;
    font-size: 0.875rem; vertical-align: top;
  }
  .feat-table tr:last-child td { border-bottom: none; }
  .feat-table td:first-child { font-weight: 500; color: #0c1b33; width: 30%; }

  /* ── Badges ── */
  .badge {
    display: inline-block; padding: 0.2rem 0.6rem;
    border-radius: 999px; font-size: 0.75rem; font-weight: 600;
  }
  .badge-blue     { background: #dbeafe; color: #1e40af; }
  .badge-purple   { background: #ede9fe; color: #5b21b6; }
  .badge-green    { background: #d1fae5; color: #065f46; }
  .badge-amber    { background: #fef3c7; color: #92400e; }
  .badge-red      { background: #fee2e2; color: #991b1b; }

  /* ── Info box ── */
  .info-box {
    background: #f0f9ff; border: 1px solid #bae6fd;
    border-radius: 8px; padding: 1rem 1.25rem;
    margin: 1rem 0; font-size: 0.875rem; color: #0c4a6e;
  }

  /* ── Print styles ── */
  @media print {
    body { background: #fff; font-size: 10pt; }
    .toolbar { display: none !important; }
    .doc-wrap { margin: 0; padding: 0; max-width: 100%; }
    .cover { border-radius: 0; margin-bottom: 0; }
    .cover::before { display: none; }
    .section, .toc { border-radius: 0; border: none; border-bottom: 1px solid #e2e8f0; }
    .section { page-break-inside: avoid; }
    .page-break { page-break-before: always; }
    a { color: inherit; text-decoration: none; }
    .role-grid { grid-template-columns: repeat(3, 1fr); }
  }
</style>
</head>
<body>

<!-- Toolbar (screen only) -->
<div class="toolbar">
  <div class="toolbar-brand">In<span>Place</span> — Application Guide</div>
  <div class="toolbar-actions">
    <a href="javascript:history.back()" class="btn-back">← Back</a>
    <button class="btn-download" onclick="window.print()">
      ⬇ Download as PDF
    </button>
  </div>
</div>

<div class="doc-wrap">

  <!-- ══════════════════════════════
       COVER PAGE
  ══════════════════════════════ -->
  <div class="cover">
    <div class="cover-label">University Placement Management System</div>
    <h1 class="cover-title">In<span>Place</span><br>Application Guide</h1>
    <p class="cover-subtitle">A complete overview of the InPlace platform — features, workflows, and user roles.</p>
    <div class="cover-meta">
      <div><strong>Prepared for</strong><?= htmlspecialchars($userName) ?> (<?= htmlspecialchars($userRole) ?>)</div>
      <div><strong>Generated</strong><?= $today ?></div>
      <div><strong>Version</strong>1.0 — Academic Year 2025/26</div>
    </div>
  </div>

  <!-- ══════════════════════════════
       TABLE OF CONTENTS
  ══════════════════════════════ -->
  <div class="toc">
    <h2>Contents</h2>
    <ul class="toc-list">
      <li><a href="#s1">1. What is InPlace?</a><span class="toc-num">Overview</span></li>
      <li><a href="#s2">2. User Roles</a><span class="toc-num">Roles</span></li>
      <li><a href="#s3">3. Student Journey</a><span class="toc-num">Workflow</span></li>
      <li><a href="#s4">4. Provider Journey</a><span class="toc-num">Workflow</span></li>
      <li><a href="#s5">5. Tutor Journey</a><span class="toc-num">Workflow</span></li>
      <li><a href="#s6">6. Admin &amp; Director</a><span class="toc-num">Workflow</span></li>
      <li><a href="#s7">7. Key Features</a><span class="toc-num">Features</span></li>
      <li><a href="#s8">8. Email Notifications</a><span class="toc-num">Comms</span></li>
      <li><a href="#s9">9. Security &amp; Access</a><span class="toc-num">Security</span></li>
    </ul>
  </div>

  <!-- ══════════════════════════════
       SECTION 1 — WHAT IS INPLACE
  ══════════════════════════════ -->
  <div class="section" id="s1">
    <div class="section-header">
      <div class="section-num">1</div>
      <h2>What is InPlace?</h2>
    </div>
    <p>
      <strong>InPlace</strong> is a web-based Placement Management System developed to help universities manage the full lifecycle of student work placements — from initial application through to completion and reporting.
    </p>
    <p>
      The system connects four key parties: <strong>Students</strong>, who find and apply for placements; <strong>Placement Providers</strong> (companies/employers), who confirm and supervise students; <strong>Tutors</strong>, who oversee academic quality and conduct visits; and <strong>Administrators</strong>, who manage the platform.
    </p>
    <p>
      InPlace replaces manual, paper-based processes with a structured digital workflow, automated email notifications, real-time status tracking, and data-driven reporting — all accessible through a modern browser interface.
    </p>
    <div class="info-box">
      InPlace is built with PHP, MySQL, and standard web technologies. It runs on a university web server and is accessible to all authorised users via their university email address.
    </div>

    <h3>Core Objectives</h3>
    <ul>
      <li>Digitalise and streamline the placement authorisation process</li>
      <li>Provide real-time visibility of placement status to all stakeholders</li>
      <li>Enable tutors to schedule and record student visits</li>
      <li>Allow students to submit interim and final placement reports</li>
      <li>Give administrators and directors data-driven insight into placement performance</li>
      <li>Automatically notify all parties at each stage via email</li>
    </ul>
  </div>

  <!-- ══════════════════════════════
       SECTION 2 — USER ROLES
  ══════════════════════════════ -->
  <div class="section" id="s2">
    <div class="section-header">
      <div class="section-num">2</div>
      <h2>User Roles</h2>
    </div>
    <p>InPlace has five distinct user roles, each with a tailored dashboard and set of permissions:</p>

    <div class="role-grid">
      <div class="role-card student">
        <div class="role-icon">🎓</div>
        <h4>Student</h4>
        <p>Submits placement requests, uploads reports, tracks progress, communicates with their tutor, and attends scheduled visits.</p>
      </div>
      <div class="role-card tutor">
        <div class="role-icon">👨‍🏫</div>
        <h4>Tutor</h4>
        <p>Reviews and approves placement requests, schedules visits, monitors student progress, submits visit notes, and reviews reports.</p>
      </div>
      <div class="role-card provider">
        <div class="role-icon">🏢</div>
        <h4>Placement Provider</h4>
        <p>Confirms placement details, manages student performance, evaluates students, schedules tutor visits, and reports any issues.</p>
      </div>
      <div class="role-card admin">
        <div class="role-icon">⚙️</div>
        <h4>Administrator</h4>
        <p>Manages user accounts, oversees all placements, approves registrations, configures system settings, and exports data.</p>
      </div>
      <div class="role-card director">
        <div class="role-icon">📊</div>
        <h4>Programme Director</h4>
        <p>Views placement analytics, monitors at-risk students, reviews employer feedback, and exports reports for strategic decisions.</p>
      </div>
    </div>

    <h3>Registration &amp; Access</h3>
    <p>
      <strong>Students</strong> self-register using their university email address and must verify it with a one-time password (OTP). Their account is then reviewed and activated by an Administrator before they can log in.
    </p>
    <p>
      <strong>Placement Providers</strong> register via a dedicated provider registration page, selecting or creating their company profile. Like students, their account is activated by an Admin.
    </p>
    <p>
      <strong>Tutors, Admins, and Directors</strong> are accounts created directly by Administrators through the User Management panel.
    </p>
  </div>

  <!-- ══════════════════════════════
       SECTION 3 — STUDENT JOURNEY
  ══════════════════════════════ -->
  <div class="section page-break" id="s3">
    <div class="section-header">
      <div class="section-num">3</div>
      <h2>Student Journey</h2>
    </div>
    <p>The following steps describe the complete lifecycle of a student's placement from registration to completion:</p>

    <div class="workflow">
      <div class="step">
        <div class="step-num">1</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Register &amp; Verify</h4>
          <p>Student registers with their university email, verifies with an OTP code, and waits for Admin activation.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">2</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Submit Placement Request</h4>
          <p>Student fills in the placement form — company name, address, role, supervisor details, start/end dates, and salary. They can save a draft and return later, or submit directly. Supporting documents (e.g. offer letter) can be attached.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">3</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Provider Confirmation</h4>
          <p>The placement provider receives an email with a one-click approve/decline link (no login required). They can also log in to InPlace to review and respond. The student is notified by email of the outcome.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">4</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Tutor Approval</h4>
          <p>Once the provider confirms, the placement moves to the assigned tutor for academic approval. The tutor reviews the details, may add comments, and approves or rejects. The student is notified by email.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">5</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Placement Active</h4>
          <p>The student's dashboard shows the placement as active with a live progress tracker. They can view their company details, tutor contact, and upcoming scheduled visits.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">6</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Reports &amp; Reflections</h4>
          <p>During the placement, students upload an Interim Report (mid-point) and a Final Report (at completion). They can also write personal reflections directly in the system. Reports are reviewed by the tutor.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">7</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Visits</h4>
          <p>The tutor schedules one or more visits (in-person or online). The student is notified, can confirm attendance, and the visit is recorded with notes. Visit history is available to all parties.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">8</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Request Changes (if needed)</h4>
          <p>If circumstances change, the student can submit a formal Change Request (e.g. extend end date, change role, change supervisor). The request follows the same provider → tutor approval chain.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">9</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Completion</h4>
          <p>At the end of the placement year, the final report is submitted and reviewed. The placement record is archived for the Programme Director's annual reporting.</p>
        </div>
      </div>
    </div>

    <h3>Student Dashboard</h3>
    <p>The student dashboard provides at a glance:</p>
    <ul>
      <li><strong>Status Tracker</strong> — shows which stage the placement is at (Submitted → Provider Confirmed → Tutor Approved → Active)</li>
      <li><strong>Placement Progress Bar</strong> — percentage of placement duration completed</li>
      <li><strong>Next Visit</strong> — date, time, type, and location of the next scheduled visit</li>
      <li><strong>Reports Status</strong> — how many reports have been submitted</li>
      <li><strong>Unread Messages &amp; Announcements</strong> — sidebar badges</li>
    </ul>
  </div>

  <!-- ══════════════════════════════
       SECTION 4 — PROVIDER JOURNEY
  ══════════════════════════════ -->
  <div class="section" id="s4">
    <div class="section-header">
      <div class="section-num">4</div>
      <h2>Placement Provider Journey</h2>
    </div>
    <p>Placement providers are companies or employers who host students. They interact with InPlace to confirm placements, monitor students, and communicate with the university.</p>

    <div class="workflow">
      <div class="step">
        <div class="step-num">1</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Register on InPlace</h4>
          <p>The provider registers via the Provider Registration page, linking their account to their company. If the company already exists in the system it is automatically matched by name.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">2</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Receive Placement Request</h4>
          <p>When a student submits a request, the provider receives an email with a secure one-click link to Approve or Decline — no login required. Registered providers can also review all requests in the Auth Requests dashboard.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">3</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Approve or Decline</h4>
          <p>The provider reviews the student's details and either approves the placement (forwarding it to the tutor for academic sign-off) or declines with a comment.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">4</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Manage Active Students</h4>
          <p>The My Students section lists all active students at the company. The provider can message students, view their progress, evaluate their performance, and report any issues.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">5</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Schedule &amp; Confirm Visits</h4>
          <p>When a tutor schedules a visit, the provider is notified. They can confirm visit logistics, such as site access and meeting room availability.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">6</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Post Placement Opportunities</h4>
          <p>Providers can list new placement opportunities directly on the platform, visible to students and tutors browsing the Opportunities directory.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════
       SECTION 5 — TUTOR JOURNEY
  ══════════════════════════════ -->
  <div class="section page-break" id="s5">
    <div class="section-header">
      <div class="section-num">5</div>
      <h2>Tutor Journey</h2>
    </div>
    <p>Tutors act as the academic link between students and the university. They are responsible for authorising placements, conducting visits, reviewing reports, and monitoring student wellbeing.</p>

    <div class="workflow">
      <div class="step">
        <div class="step-num">1</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Review Placement Requests</h4>
          <p>After provider approval, the tutor receives the placement in their Auth Requests queue. They review all details, may add a comment, and give final academic approval or rejection.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">2</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Monitor All Placements</h4>
          <p>The All Placements view lists every student the tutor is responsible for, with status indicators, risk flags, and report submission status at a glance.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">3</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Schedule Visits</h4>
          <p>Tutors schedule visits (in-person or video call) via the Visit Planner. Students and providers are automatically notified. Visits can be rescheduled if needed.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">4</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Record Visit Notes</h4>
          <p>After each visit, the tutor records structured notes covering progress, concerns, and actions. These are stored against the visit record and visible to authorised parties.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">5</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Review Student Reports</h4>
          <p>The Reports section shows all submitted interim and final reports. The tutor can view the document, leave feedback, and mark it as reviewed.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">6</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Monitor At-Risk Students</h4>
          <p>The At-Risk dashboard flags students with high-risk indicators (e.g. overdue reports, unvisited, issues reported). Tutors can take action directly from this view.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">7</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Map View</h4>
          <p>An interactive map shows the geographical spread of all student placements, helping tutors plan visit routes efficiently.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">8</div>
        <div class="step-line"></div>
        <div class="step-body">
          <h4>Announcements</h4>
          <p>Tutors can broadcast announcements to all students, a specific academic year group, or a specific programme type.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════
       SECTION 6 — ADMIN & DIRECTOR
  ══════════════════════════════ -->
  <div class="section" id="s6">
    <div class="section-header">
      <div class="section-num">6</div>
      <h2>Admin &amp; Programme Director</h2>
    </div>

    <h3>Administrator</h3>
    <p>The Administrator manages the platform and all user accounts. Key responsibilities include:</p>
    <ul>
      <li><strong>Registration Approvals</strong> — review and activate pending student and provider registrations</li>
      <li><strong>User Management</strong> — create, edit, activate, deactivate, or permanently delete any user account, including cascading deletion of all associated data</li>
      <li><strong>Placement Oversight</strong> — view and manage all placements in the system across all tutors</li>
      <li><strong>System Settings</strong> — configure SMTP email settings, application name, and academic year parameters</li>
      <li><strong>Audit Logs</strong> — view a full audit trail of all actions taken within the system</li>
      <li><strong>Data Export</strong> — export placement data to CSV/Excel for external reporting</li>
    </ul>

    <h3>Programme Director</h3>
    <p>The Programme Director has a read-only analytical view of all placement data. Key features include:</p>
    <ul>
      <li><strong>Overview Dashboard</strong> — total placements, approval rates, visit completion rates, and at-risk counts</li>
      <li><strong>Interactive Map</strong> — visualise where students are placed geographically</li>
      <li><strong>At-Risk Monitor</strong> — identify students flagged as high-risk across all tutors</li>
      <li><strong>Employer Feedback</strong> — aggregate view of provider evaluations and satisfaction scores</li>
      <li><strong>Reports &amp; Exports</strong> — generate and download placement data exports for the academic year</li>
    </ul>
  </div>

  <!-- ══════════════════════════════
       SECTION 7 — KEY FEATURES
  ══════════════════════════════ -->
  <div class="section page-break" id="s7">
    <div class="section-header">
      <div class="section-num">7</div>
      <h2>Key Features</h2>
    </div>

    <table class="feat-table">
      <thead>
        <tr>
          <th>Feature</th>
          <th>Description</th>
          <th>Who Uses It</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>OTP Email Verification</td>
          <td>Students verify their university email with a one-time password during registration, ensuring only genuine university accounts are registered.</td>
          <td><span class="badge badge-blue">Student</span></td>
        </tr>
        <tr>
          <td>Placement Request Workflow</td>
          <td>A structured multi-step authorisation process: Student submits → Provider confirms → Tutor approves. Each step triggers automated email notifications.</td>
          <td><span class="badge badge-blue">Student</span> <span class="badge badge-green">Provider</span> <span class="badge badge-purple">Tutor</span></td>
        </tr>
        <tr>
          <td>One-Click Provider Approval</td>
          <td>Providers receive a secure, time-limited link in their email. They can approve or decline a placement without needing to log in to InPlace.</td>
          <td><span class="badge badge-green">Provider</span></td>
        </tr>
        <tr>
          <td>Status Tracker</td>
          <td>A visual step-by-step progress indicator on the student dashboard shows exactly where their placement application stands in real time.</td>
          <td><span class="badge badge-blue">Student</span></td>
        </tr>
        <tr>
          <td>Document Management</td>
          <td>Students upload offer letters, interim reports, and final reports. Tutors can view, download, and mark documents as reviewed.</td>
          <td><span class="badge badge-blue">Student</span> <span class="badge badge-purple">Tutor</span></td>
        </tr>
        <tr>
          <td>Visit Planner</td>
          <td>Tutors schedule in-person or online visits, set reminders, record notes, and track visit history. Calendar reminders are sent automatically.</td>
          <td><span class="badge badge-purple">Tutor</span> <span class="badge badge-blue">Student</span></td>
        </tr>
        <tr>
          <td>Interactive Map</td>
          <td>Displays all active placements on a geographical map. Tutors can plan visit routes; the Director uses it for strategic oversight.</td>
          <td><span class="badge badge-purple">Tutor</span> <span class="badge badge-red">Director</span></td>
        </tr>
        <tr>
          <td>Messaging System</td>
          <td>In-app messaging between students and their assigned tutors and providers. Unread message counts appear as sidebar badges.</td>
          <td>All roles</td>
        </tr>
        <tr>
          <td>Announcements</td>
          <td>Tutors broadcast messages to specific student groups (by year or programme). Students see unread counts and can filter by read/unread.</td>
          <td><span class="badge badge-purple">Tutor</span> <span class="badge badge-blue">Student</span></td>
        </tr>
        <tr>
          <td>Placement Change Requests</td>
          <td>Students formally request changes (end date, role, supervisor, transfer) during the placement. The request goes through the same provider → tutor approval chain.</td>
          <td><span class="badge badge-blue">Student</span> <span class="badge badge-green">Provider</span> <span class="badge badge-purple">Tutor</span></td>
        </tr>
        <tr>
          <td>Reflections</td>
          <td>Students write structured personal reflections throughout their placement. These are stored privately and can be reviewed by the tutor.</td>
          <td><span class="badge badge-blue">Student</span> <span class="badge badge-purple">Tutor</span></td>
        </tr>
        <tr>
          <td>Provider Evaluations</td>
          <td>Providers submit structured evaluations of the student's performance during and at the end of the placement.</td>
          <td><span class="badge badge-green">Provider</span></td>
        </tr>
        <tr>
          <td>At-Risk Monitoring</td>
          <td>Automatic risk flagging based on indicators such as no visits scheduled, overdue reports, or issues raised. High-risk students appear in a dedicated dashboard.</td>
          <td><span class="badge badge-purple">Tutor</span> <span class="badge badge-red">Director</span></td>
        </tr>
        <tr>
          <td>Calendar</td>
          <td>A shared calendar view showing visits, deadlines, and key dates. Supports .ics export for Google Calendar / Outlook.</td>
          <td>All roles</td>
        </tr>
        <tr>
          <td>Placement Opportunities</td>
          <td>Providers post available placement opportunities. Students and tutors can browse and express interest.</td>
          <td><span class="badge badge-green">Provider</span> <span class="badge badge-blue">Student</span></td>
        </tr>
        <tr>
          <td>Audit Trail</td>
          <td>Every significant action in the system (submission, approval, upload, login) is logged with user, timestamp, and IP address for compliance purposes.</td>
          <td><span class="badge badge-amber">Admin</span></td>
        </tr>
        <tr>
          <td>Data Export</td>
          <td>Admins and Directors can export placement data, student lists, and visit reports to CSV/Excel.</td>
          <td><span class="badge badge-amber">Admin</span> <span class="badge badge-red">Director</span></td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- ══════════════════════════════
       SECTION 8 — EMAIL NOTIFICATIONS
  ══════════════════════════════ -->
  <div class="section" id="s8">
    <div class="section-header">
      <div class="section-num">8</div>
      <h2>Email Notifications</h2>
    </div>
    <p>InPlace automatically sends HTML-formatted emails at every key point in the placement lifecycle so no one needs to manually chase approvals:</p>

    <table class="feat-table">
      <thead>
        <tr><th>Event</th><th>Who Receives Email</th></tr>
      </thead>
      <tbody>
        <tr><td>Student submits placement request</td><td>Provider (with one-click approve/decline link) + Student (confirmation)</td></tr>
        <tr><td>Provider approves placement</td><td>Tutor (for academic review) + Student (provider confirmed)</td></tr>
        <tr><td>Provider declines placement</td><td>Student (with provider's reason)</td></tr>
        <tr><td>Tutor approves placement</td><td>Student (placement fully approved)</td></tr>
        <tr><td>Tutor rejects placement</td><td>Student (with tutor's feedback)</td></tr>
        <tr><td>Tutor schedules a visit</td><td>Student + Provider (visit details and calendar link)</td></tr>
        <tr><td>Student submits change request</td><td>Provider (to review the change)</td></tr>
        <tr><td>OTP verification</td><td>Student (6-digit one-time code)</td></tr>
      </tbody>
    </table>

    <p style="margin-top:1rem;">All emails are sent via SMTP (configured by the Administrator) and include the InPlace branding. Email settings can be updated at any time from the Admin Settings page.</p>
  </div>

  <!-- ══════════════════════════════
       SECTION 9 — SECURITY & ACCESS
  ══════════════════════════════ -->
  <div class="section" id="s9">
    <div class="section-header">
      <div class="section-num">9</div>
      <h2>Security &amp; Access Control</h2>
    </div>

    <h3>Authentication</h3>
    <ul>
      <li>All users log in with an email and password. Passwords are hashed using PHP's <code>PASSWORD_BCRYPT</code> algorithm — they are never stored in plain text.</li>
      <li>Student registrations require email OTP verification to confirm ownership of a university email address.</li>
      <li>All new accounts (student and provider) are held in a pending state until an Administrator explicitly activates them.</li>
    </ul>

    <h3>Role-Based Access Control</h3>
    <ul>
      <li>Every page checks the logged-in user's role on load. Accessing a page outside your role redirects you to your own dashboard.</li>
      <li>Data queries are always scoped to the current user — students can only see their own placements, providers only see their company's data, and tutors only see students assigned to them.</li>
    </ul>

    <h3>One-Time Provider Tokens</h3>
    <ul>
      <li>The approve/decline links emailed to providers contain a cryptographically random 64-character token.</li>
      <li>Tokens expire after 7 days and are single-use — once clicked, they cannot be used again.</li>
    </ul>

    <h3>Input Handling</h3>
    <ul>
      <li>All user input is sanitised with <code>htmlspecialchars()</code> before being displayed.</li>
      <li>All database queries use PDO prepared statements to prevent SQL injection.</li>
      <li>File uploads are validated by type and renamed to a safe format before storage.</li>
    </ul>

    <h3>Data Integrity</h3>
    <ul>
      <li>Critical multi-step operations (placement submission, hard delete) are wrapped in database transactions, ensuring either all changes succeed or none do.</li>
      <li>An audit log records every significant action with the user ID, action type, affected table, and IP address.</li>
    </ul>

    <div class="info-box" style="margin-top:1.25rem;">
      This document was generated automatically by InPlace on <?= $today ?> for <?= htmlspecialchars($userName) ?> (<?= htmlspecialchars($userRole) ?>). It reflects the current state of the application.
    </div>
  </div>

</div><!-- /.doc-wrap -->

<script>
// smooth scroll for TOC links
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        e.preventDefault();
        const target = document.querySelector(a.getAttribute('href'));
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});
</script>
</body>
</html>
