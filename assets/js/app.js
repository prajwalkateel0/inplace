let currentRole = 'student';
let currentView = 'dashboard';

const roles = {
  student: {
    label: 'Student',
    name: 'Jamie Smith',
    initials: 'JS',
    nav: [
      { id: 'dashboard', icon: '🏠', label: 'Dashboard' },
      { id: 'my-placement', icon: '🏢', label: 'My Placement' },
      { id: 'submit-request', icon: '📋', label: 'Submit Request' },
      { id: 'reports', icon: '📄', label: 'My Reports' },
      { id: 'visits', icon: '🗓', label: 'Visits' },
      { id: 'messages', icon: '💬', label: 'Messages', badge: '2' },
    ]
  },
  tutor: {
    label: 'Placement Tutor',
    name: 'Dr. Emily Clarke',
    initials: 'EC',
    nav: [
      { id: 'dashboard', icon: '🏠', label: 'Dashboard' },
      { id: 'all-placements', icon: '👥', label: 'All Placements' },
      { id: 'requests', icon: '📋', label: 'Auth Requests', badge: '5' },
      { id: 'map-view', icon: '🗺', label: 'Map View' },
      { id: 'visits', icon: '🗓', label: 'Visit Planner' },
      { id: 'reports', icon: '📄', label: 'Reports' },
      { id: 'messages', icon: '💬', label: 'Messages', badge: '3' },
    ]
  },
  provider: {
    label: 'Placement Provider',
    name: 'Sarah Johnson',
    initials: 'SJ',
    nav: [
      { id: 'dashboard', icon: '🏠', label: 'Dashboard' },
      { id: 'requests', icon: '📋', label: 'Auth Requests' },
      { id: 'visits', icon: '🗓', label: 'Scheduled Visits' },
      { id: 'messages', icon: '💬', label: 'Messages' },
    ]
  },
  admin: {
    label: 'Administrator',
    name: 'Admin User',
    initials: 'AU',
    nav: [
      { id: 'dashboard', icon: '🏠', label: 'Dashboard' },
      { id: 'all-placements', icon: '👥', label: 'All Placements' },
      { id: 'requests', icon: '📋', label: 'Auth Requests' },
      { id: 'reports', icon: '📄', label: 'Reports' },
    ]
  }
};

function setRole(btn, role) {
  document.querySelectorAll('.role-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  currentRole = role;
}

function login() {
  document.getElementById('loginScreen').style.display = 'none';
  document.getElementById('app').classList.add('active');
  const r = roles[currentRole];
  document.getElementById('rolePill').textContent = r.label;
  document.getElementById('sidebarName').textContent = r.name;
  document.getElementById('sidebarRole').textContent = r.label;
  document.getElementById('sidebarInitials').textContent = r.initials;
  document.getElementById('viewSubtitle').textContent = `Welcome back, ${r.name.split(' ')[0]}`;
  renderNav();
  navigateTo('dashboard');
}

function logout() {
  document.getElementById('loginScreen').style.display = 'flex';
  document.getElementById('app').classList.remove('active');
}

function renderNav() {
  const nav = document.getElementById('sidebarNav');
  nav.innerHTML = '';
  const items = roles[currentRole].nav;
  items.forEach(item => {
    const el = document.createElement('div');
    el.className = 'nav-item' + (item.id === currentView ? ' active' : '');
    el.setAttribute('data-view', item.id);
    el.onclick = () => navigateTo(item.id);
    el.innerHTML = `<span class="nav-icon">${item.icon}</span>${item.label}${item.badge ? `<span class="nav-badge">${item.badge}</span>` : ''}`;
    nav.appendChild(el);
  });
}

function navigateTo(viewId) {
  currentView = viewId;
  renderNav();
  const content = document.getElementById('pageContent');
  const viewDef = views[viewId] || views['dashboard'];
  document.getElementById('viewTitle').textContent = viewDef.title;
  content.innerHTML = viewDef.render();
  const animations = document.querySelectorAll('.bar');
  animations.forEach(b => { const h = b.getAttribute('data-h'); if (h) b.style.height = h; });
}

const views = {
  dashboard: {
    title: 'Dashboard',
    render: () => currentRole === 'tutor' ? tutorDashboard() : currentRole === 'student' ? studentDashboard() : genericDash()
  },
  'my-placement': { title: 'My Placement', render: myPlacementView },
  'submit-request': { title: 'Submit Authorisation Request', render: submitRequestView },
  'all-placements': { title: 'All Placements', render: allPlacementsView },
  'requests': { title: 'Authorisation Requests', render: requestsView },
  'map-view': { title: 'Placement Map', render: mapView },
  'visits': { title: currentRole === 'tutor' ? 'Visit Planner' : 'My Visits', render: visitsView },
  'reports': { title: 'Reports', render: reportsView },
  'messages': { title: 'Messages', render: messagesView }
};

function studentDashboard() {
  return `
  <div class="stats-grid">
    <div class="stat-card"><span class="stat-icon">🏢</span><h3>Active</h3><p>Placement Status</p><div class="stat-trend trend-up">✓ Approved since Sep 2024</div></div>
    <div class="stat-card"><span class="stat-icon">📄</span><h3>1/2</h3><p>Reports Submitted</p><div class="stat-trend trend-neutral">Interim submitted · Final due Apr 2025</div></div>
    <div class="stat-card"><span class="stat-icon">🗓</span><h3>Mar 18</h3><p>Next Visit</p><div class="stat-trend trend-up">📍 Virtual · 2:00 PM</div></div>
    <div class="stat-card"><span class="stat-icon">💬</span><h3>2</h3><p>Unread Messages</p><div class="stat-trend trend-neutral">From Dr. Clarke</div></div>
  </div>

  <div class="quick-actions">
    <div class="quick-action" onclick="navigateTo('my-placement')"><div class="qa-icon">🏢</div><div class="qa-label">My Placement</div><div class="qa-desc">View details</div></div>
    <div class="quick-action" onclick="navigateTo('submit-request')"><div class="qa-icon">📋</div><div class="qa-label">New Request</div><div class="qa-desc">Submit authorisation</div></div>
    <div class="quick-action" onclick="navigateTo('reports')"><div class="qa-icon">📄</div><div class="qa-label">Submit Report</div><div class="qa-desc">Upload placement report</div></div>
    <div class="quick-action" onclick="navigateTo('messages')"><div class="qa-icon">💬</div><div class="qa-label">Messages</div><div class="qa-desc">2 unread</div></div>
  </div>

  <div class="two-col">
    <div class="panel">
      <div class="panel-header"><h3>Request Status Tracker</h3></div>
      <div class="panel-body">
        <div class="status-track">
          <div class="status-step done"><div class="step-circle">✓</div><div class="step-label">Submitted</div></div>
          <div class="status-step done"><div class="step-circle">✓</div><div class="step-label">Provider Confirmed</div></div>
          <div class="status-step active"><div class="step-circle">▶</div><div class="step-label">Tutor Review</div></div>
          <div class="status-step"><div class="step-circle"></div><div class="step-label">Approved</div></div>
        </div>
        <div style="margin-top:1.5rem;padding:1.25rem;background:var(--cream);border-radius:var(--radius-sm);border:1px solid var(--border);">
          <p style="font-size:0.875rem;color:var(--muted);">Your request is currently with <strong style="color:var(--text)">Dr. Emily Clarke</strong> for final approval. You'll be notified by email when a decision is made.</p>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header"><h3>Upcoming Deadlines</h3></div>
      <div class="panel-body">
        <div style="display:flex;flex-direction:column;gap:1rem;">
          <div style="display:flex;align-items:center;gap:1rem;padding:1rem;background:var(--danger-bg);border-radius:var(--radius-sm);border:1px solid #fca5a5;">
            <span style="font-size:1.5rem;">📄</span>
            <div>
              <p style="font-weight:600;font-size:0.9375rem;">Final Placement Report</p>
              <p style="font-size:0.8125rem;color:var(--danger);">Due in 14 days · 30 April 2025</p>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:1rem;padding:1rem;background:var(--warning-bg);border-radius:var(--radius-sm);border:1px solid #fcd34d;">
            <span style="font-size:1.5rem;">🗓</span>
            <div>
              <p style="font-weight:600;font-size:0.9375rem;">Placement End Date</p>
              <p style="font-size:0.8125rem;color:var(--warning);">31 August 2025 · 4 months remaining</p>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:1rem;padding:1rem;background:var(--success-bg);border-radius:var(--radius-sm);border:1px solid #6ee7b7;">
            <span style="font-size:1.5rem;">✅</span>
            <div>
              <p style="font-weight:600;font-size:0.9375rem;">Interim Report</p>
              <p style="font-size:0.8125rem;color:var(--success);">Submitted · Marked as reviewed</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>`;
}

function tutorDashboard() {
  return `
  <div class="stats-grid">
    <div class="stat-card"><span class="stat-icon">👥</span><h3>48</h3><p>Students on Placement</p><div class="stat-trend trend-up">↑ 8 more than last year</div></div>
    <div class="stat-card"><span class="stat-icon">📋</span><h3>5</h3><p>Pending Requests</p><div class="stat-trend trend-neutral">3 awaiting provider · 2 ready to review</div></div>
    <div class="stat-card"><span class="stat-icon">🗓</span><h3>7</h3><p>Visits This Month</p><div class="stat-trend trend-up">3 physical · 4 virtual</div></div>
    <div class="stat-card"><span class="stat-icon">⚠️</span><h3>4</h3><p>Overdue Reports</p><div class="stat-trend" style="color:var(--danger);">Requires attention</div></div>
  </div>

  <div class="quick-actions">
    <div class="quick-action" onclick="navigateTo('all-placements')"><div class="qa-icon">👥</div><div class="qa-label">All Placements</div><div class="qa-desc">48 active students</div></div>
    <div class="quick-action" onclick="navigateTo('requests')"><div class="qa-icon">📋</div><div class="qa-label">Auth Requests</div><div class="qa-desc">5 pending review</div></div>
    <div class="quick-action" onclick="navigateTo('map-view')"><div class="qa-icon">🗺</div><div class="qa-label">Map View</div><div class="qa-desc">View all companies</div></div>
    <div class="quick-action" onclick="navigateTo('visits')"><div class="qa-icon">🗓</div><div class="qa-label">Plan Visits</div><div class="qa-desc">Optimise route</div></div>
  </div>

  <div class="two-col">
    <div class="panel">
      <div class="panel-header"><h3>Placements by Region</h3><button class="btn btn-ghost btn-sm" onclick="navigateTo('map-view')">View Map →</button></div>
      <div class="panel-body">
        <div style="display:flex;flex-direction:column;gap:0.875rem;">
          ${[['South Yorkshire','Sheffield, Rotherham',18,'navy'],['West Yorkshire','Leeds, Bradford',12,'gold'],['London','City, Canary Wharf',9,'navy'],['Manchester','Salford, Media City',6,'gold'],['Remote / Virtual','Various',3,'muted']].map(([r,c,n,col])=>`
          <div style="display:flex;align-items:center;gap:1rem;">
            <div style="width:100px;font-size:0.875rem;font-weight:500;color:var(--text);flex-shrink:0;">${r}</div>
            <div style="flex:1;">
              <div class="progress-bar"><div class="progress-fill" style="width:${Math.round(n/48*100)}%;background:${col==='gold'?'var(--gold)':col==='muted'?'var(--muted)':'var(--navy)'};"></div></div>
            </div>
            <div style="width:30px;text-align:right;font-size:0.875rem;font-weight:600;color:var(--text);">${n}</div>
          </div>`).join('')}
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header"><h3>Report Submission</h3></div>
      <div class="panel-body">
        <div class="chart-placeholder">
          ${[40,65,52,80,73,88,60,90,78,95,70,85].map((h,i)=>`<div class="bar ${i%2===0?'bar-navy':'bar-gold'}" data-h="${h}%" style="height:0%"></div>`).join('')}
        </div>
        <div style="display:flex;gap:1.5rem;margin-top:1.25rem;font-size:0.8125rem;">
          <span style="display:flex;align-items:center;gap:0.4rem;"><span style="width:10px;height:10px;background:var(--navy);border-radius:2px;display:inline-block;"></span>Submitted</span>
          <span style="display:flex;align-items:center;gap:0.4rem;"><span style="width:10px;height:10px;background:var(--gold);border-radius:2px;display:inline-block;"></span>Reviewed</span>
        </div>
      </div>
    </div>
  </div>`;
}

function genericDash() {
  return `<div class="stats-grid">
    <div class="stat-card"><span class="stat-icon">📋</span><h3>2</h3><p>Pending Confirmations</p></div>
    <div class="stat-card"><span class="stat-icon">👤</span><h3>3</h3><p>Students at Your Company</p></div>
    <div class="stat-card"><span class="stat-icon">🗓</span><h3>1</h3><p>Upcoming Visit</p></div>
    <div class="stat-card"><span class="stat-icon">💬</span><h3>1</h3><p>New Messages</p></div>
  </div>
  <div class="panel"><div class="panel-header"><h3>Actions Required</h3></div><div class="panel-body"><p style="color:var(--muted);">No urgent actions at this time.</p></div></div>`;
}

function myPlacementView() {
  return `
  <div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-header">
      <div><h3>Current Placement</h3><p>Effective from 1 September 2024</p></div>
      <div style="display:flex;gap:0.75rem;">
        <span class="badge badge-approved">Approved</span>
        <button class="btn btn-ghost btn-sm">Request Change</button>
      </div>
    </div>
    <div class="panel-body">
      <div class="info-grid" style="margin-bottom:2rem;">
        <div class="info-item"><label>Company</label><p>Rolls-Royce plc</p></div>
        <div class="info-item"><label>Role</label><p>Software Engineering Intern</p></div>
        <div class="info-item"><label>Location</label><p>Derby, UK</p></div>
        <div class="info-item"><label>Start Date</label><p>1 September 2024</p></div>
        <div class="info-item"><label>End Date</label><p>31 August 2025</p></div>
        <div class="info-item"><label>Salary</label><p>£22,000 per annum</p></div>
        <div class="info-item"><label>Supervisor</label><p>Mark Henderson</p></div>
        <div class="info-item"><label>Supervisor Email</label><p>m.henderson@rolls-royce.com</p></div>
        <div class="info-item"><label>Placement Tutor</label><p>Dr. Emily Clarke</p></div>
      </div>
      <div class="divider"></div>
      <h4 style="font-size:1rem;font-weight:600;margin-bottom:1.25rem;">Placement Progress</h4>
      <div style="display:flex;align-items:center;gap:1rem;margin-bottom:0.5rem;">
        <div style="flex:1;"><div class="progress-bar" style="height:10px;"><div class="progress-fill" style="width:65%;height:100%;"></div></div></div>
        <div style="font-size:0.875rem;font-weight:600;color:var(--navy);">65% Complete</div>
      </div>
      <p style="font-size:0.8125rem;color:var(--muted);">7.8 months completed of 12-month placement</p>
    </div>
  </div>

  <div class="two-col">
    <div class="panel">
      <div class="panel-header"><h3>Documents</h3><button class="btn btn-primary btn-sm">Upload New</button></div>
      <div class="panel-body">
        ${[['Offer Letter','PDF · 2.1 MB','Uploaded 01 Sep 2024'],['Job Description','PDF · 845 KB','Uploaded 01 Sep 2024'],['Interim Report','PDF · 3.4 MB','Submitted 15 Jan 2025']].map(([n,m,d])=>`
        <div style="display:flex;align-items:center;gap:1rem;padding:0.875rem 0;border-bottom:1px solid var(--border);">
          <div style="width:40px;height:40px;background:var(--info-bg);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;">📄</div>
          <div style="flex:1;"><p style="font-weight:500;font-size:0.9375rem;">${n}</p><p style="font-size:0.8125rem;color:var(--muted);">${m} · ${d}</p></div>
          <button class="btn btn-ghost btn-sm">⬇ Download</button>
        </div>`).join('')}
      </div>
    </div>
    <div class="panel">
      <div class="panel-header"><h3>Weekly Reflection Log</h3><button class="btn btn-primary btn-sm">+ Add Entry</button></div>
      <div class="panel-body">
        ${[['Week 30 · Mar 10','Worked on migrating legacy API endpoints to REST. Learned about versioning strategies.'],['Week 29 · Mar 3','Completed sprint review presentation. Received positive feedback from team lead.'],['Week 28 · Feb 24','Started new feature for telemetry dashboard. Initial design approved.']].map(([w,t])=>`
        <div style="padding:0.875rem 0;border-bottom:1px solid var(--border);">
          <p style="font-size:0.75rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.3rem;">${w}</p>
          <p style="font-size:0.875rem;color:var(--text);line-height:1.5;">${t}</p>
        </div>`).join('')}
      </div>
    </div>
  </div>`;
}

function submitRequestView() {
  return `
  <div class="panel">
    <div class="panel-header">
      <div><h3>New Placement Authorisation Request</h3><p>All fields marked * are required</p></div>
      <span class="badge badge-pending">Draft</span>
    </div>
    <div class="panel-body">

      <div style="font-size:0.8125rem;font-weight:600;text-transform:uppercase;letter-spacing:0.07em;color:var(--muted);margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border);">1. Company & Role Information</div>
      <div class="form-grid" style="margin-bottom:2rem;">
        <div class="form-group"><label>Company Name *</label><input type="text" placeholder="e.g., Rolls-Royce plc"></div>
        <div class="form-group"><label>Company Address *</label><input type="text" placeholder="Full address including postcode"></div>
        <div class="form-group"><label>Company Sector</label><select><option>Select sector</option><option>Technology & Software</option><option>Engineering & Manufacturing</option><option>Finance & Banking</option><option>Healthcare</option><option>Consultancy</option><option>Other</option></select></div>
        <div class="form-group"><label>Role / Job Title *</label><input type="text" placeholder="e.g., Software Engineering Intern"></div>
        <div class="form-group full-col"><label>Job Description *</label><textarea placeholder="Describe the role, responsibilities, and skills involved..."></textarea></div>
      </div>

      <div style="font-size:0.8125rem;font-weight:600;text-transform:uppercase;letter-spacing:0.07em;color:var(--muted);margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border);">2. Placement Details</div>
      <div class="form-grid" style="margin-bottom:2rem;">
        <div class="form-group"><label>Start Date *</label><input type="date" value="2025-09-01"></div>
        <div class="form-group"><label>End Date *</label><input type="date" value="2026-08-31"></div>
        <div class="form-group"><label>Salary (Annual)</label><input type="text" placeholder="e.g., £22,000"></div>
        <div class="form-group"><label>Working Pattern</label><select><option>Full-time (37.5 hrs/week)</option><option>Full-time (40 hrs/week)</option><option>Part-time</option><option>Hybrid</option></select></div>
      </div>

      <div style="font-size:0.8125rem;font-weight:600;text-transform:uppercase;letter-spacing:0.07em;color:var(--muted);margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border);">3. Supervisor Details</div>
      <div class="form-grid" style="margin-bottom:2rem;">
        <div class="form-group"><label>Supervisor Name *</label><input type="text" placeholder="e.g., Mark Henderson"></div>
        <div class="form-group"><label>Supervisor Job Title</label><input type="text" placeholder="e.g., Engineering Manager"></div>
        <div class="form-group"><label>Supervisor Email *</label><input type="email" placeholder="supervisor@company.com"></div>
        <div class="form-group"><label>Supervisor Phone</label><input type="tel" placeholder="+44 7700 000000"></div>
      </div>

      <div style="font-size:0.8125rem;font-weight:600;text-transform:uppercase;letter-spacing:0.07em;color:var(--muted);margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border);">4. Supporting Documents</div>
      <div class="upload-zone" style="margin-bottom:2rem;">
        <div style="font-size:2rem;margin-bottom:0.5rem;">📎</div>
        <p><strong>Click to upload</strong> or drag and drop your documents here</p>
        <p style="font-size:0.8125rem;margin-top:0.25rem;">Offer letter, job description PDF (max 10 MB each)</p>
      </div>

      <div class="divider"></div>
      <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1.5rem;">
        <button class="btn btn-ghost">Save as Draft</button>
        <button class="btn btn-primary">Submit Request →</button>
      </div>
    </div>
  </div>`;
}

function allPlacementsView() {
  const students = [
    ['JS','Jamie Smith','Rolls-Royce, Derby','Software Eng Intern','01 Sep 2024','31 Aug 2025','Approved'],
    ['AK','Aisha Khan','HSBC, London','Data Analyst Intern','02 Sep 2024','31 Aug 2025','Approved'],
    ['TN','Tom Nguyen','Siemens, Sheffield','Embedded Sys. Intern','01 Sep 2024','28 Feb 2025','Approved'],
    ['RL','Rachel Lee','PwC, Leeds','Tech Consulting Intern','03 Sep 2024','31 Aug 2025','Approved'],
    ['DM','David Martinez','Dyson, Malmesbury','Mech. Eng. Intern','01 Sep 2024','31 Aug 2025','Approved'],
    ['HP','Hannah Park','NHS Digital, Leeds','Dev Intern','08 Sep 2024','07 Sep 2025','Approved'],
    ['OA','Oliver Adams','Google, London','SWE Intern','02 Sep 2024','01 Sep 2025','Approved'],
  ];
  return `
  <div class="filter-bar">
    <input type="text" placeholder="🔍  Search by name, company or location...">
    <select><option>All Statuses</option><option>Approved</option><option>Pending</option><option>Rejected</option></select>
    <select><option>All Locations</option><option>Sheffield</option><option>Leeds</option><option>London</option><option>Derby</option></select>
    <select><option>All Companies</option><option>Rolls-Royce</option><option>HSBC</option><option>Google</option></select>
    <div style="margin-left:auto;display:flex;gap:0.75rem;">
      <button class="btn btn-ghost btn-sm">⬇ Export CSV</button>
      <button class="btn btn-primary btn-sm">+ Add Manually</button>
    </div>
  </div>
  <div class="panel">
    <div class="panel-header"><h3>48 Students on Placement</h3><p>Academic Year 2024–25</p></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Student</th><th>Company & Location</th><th>Role</th><th>Start</th><th>End</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          ${students.map(([i,n,c,r,s,e,st])=>`<tr>
            <td><div class="avatar-cell"><div class="avatar">${i}</div><div><h4>${n}</h4><p>Student ID: 231${Math.floor(Math.random()*9000+1000)}</p></div></div></td>
            <td>${c}</td>
            <td>${r}</td>
            <td style="font-family:'DM Mono',monospace;font-size:0.8125rem;">${s}</td>
            <td style="font-family:'DM Mono',monospace;font-size:0.8125rem;">${e}</td>
            <td><span class="badge badge-approved">${st}</span></td>
            <td><div style="display:flex;gap:0.5rem;"><button class="btn btn-ghost btn-sm">View</button><button class="btn btn-primary btn-sm">Edit</button></div></td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>
  </div>`;
}

function requestsView() {
  const reqs = [
    ['JS','Jamie Smith','Rolls-Royce plc','DTC','01 Sep 2024','Awaiting Tutor Approval','pending'],
    ['AK','Aisha Khan','HSBC Bank','DTC','02 Sep 2024','Provider Confirmed','review'],
    ['MR','Maria Reyes','Amazon UK','DID','05 Sep 2024','Submitted','open'],
    ['BW','Ben Williams','Siemens AG','DTC','01 Sep 2024','Approved','approved'],
    ['CL','Clara Liu','Deloitte LLP','DID','03 Sep 2024','Rejected','rejected'],
  ];
  return `
  <div class="filter-bar">
    <input type="text" placeholder="🔍  Search requests...">
    <select><option>All Statuses</option><option>Submitted</option><option>Awaiting Provider</option><option>Awaiting Tutor</option><option>Approved</option><option>Rejected</option></select>
    <input type="date">
  </div>
  <div class="panel">
    <div class="panel-header"><h3>Authorisation Requests</h3><p>5 pending action</p></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Student</th><th>Company</th><th>Type</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          ${reqs.map(([i,n,c,t,d,st,cls])=>`<tr>
            <td><div class="avatar-cell"><div class="avatar">${i}</div><div><h4>${n}</h4></div></div></td>
            <td>${c}</td>
            <td><span class="type-chip">${t}</span></td>
            <td style="font-size:0.875rem;color:var(--muted);">${d}</td>
            <td><span class="badge badge-${cls}">${st}</span></td>
            <td><div style="display:flex;gap:0.5rem;">
              <button class="btn btn-primary btn-sm">Review</button>
              ${cls==='pending'||cls==='review'?'<button class="btn btn-success btn-sm">✓ Approve</button><button class="btn btn-danger btn-sm">✗ Reject</button>':''}
            </div></td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>
  </div>`;
}

function mapView() {
  const pins = [
    {x:'24%',y:'28%',label:'Sheffield (7)',cls:'pin-cluster'},
    {x:'35%',y:'22%',label:'Leeds (5)',cls:'pin-cluster'},
    {x:'78%',y:'52%',label:'London (9)',cls:'pin-navy'},
    {x:'42%',y:'18%',label:'York (2)',cls:'pin-navy'},
    {x:'28%',y:'45%',label:'Derby (3)',cls:'pin-gold'},
    {x:'32%',y:'16%',label:'Bradford (2)',cls:'pin-navy'},
    {x:'60%',y:'30%',label:'Lincoln (1)',cls:'pin-navy'},
    {x:'70%',y:'38%',label:'Norwich (1)',cls:'pin-navy'},
    {x:'55%',y:'20%',label:'Nottingham (3)',cls:'pin-gold'},
  ];
  return `
  <div style="display:flex;gap:1.25rem;margin-bottom:1.25rem;">
    <div class="panel" style="flex:1;margin-bottom:0;padding:1.25rem 1.75rem;">
      <div style="display:flex;align-items:center;gap:1rem;">
        <span style="font-size:1.75rem;">🔵</span>
        <div><p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);">Total Placements</p><h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;color:var(--navy);">48</h3></div>
      </div>
    </div>
    <div class="panel" style="flex:1;margin-bottom:0;padding:1.25rem 1.75rem;">
      <div style="display:flex;align-items:center;gap:1rem;">
        <span style="font-size:1.75rem;">📍</span>
        <div><p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);">Regions Covered</p><h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;color:var(--navy);">9</h3></div>
      </div>
    </div>
    <div class="panel" style="flex:2;margin-bottom:0;padding:1.25rem 1.75rem;">
      <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">Smart Visit Suggestion</p>
      <p style="font-size:0.875rem;color:var(--text);">🧠 <strong>7 students</strong> in Yorkshire cluster — schedule a batch visit to save travel time. <span style="color:var(--success);font-weight:600;">Estimated saving: 4.5 hrs</span></p>
      <button class="btn btn-gold btn-sm" style="margin-top:0.625rem;">Plan Yorkshire Visit →</button>
    </div>
  </div>

  <div class="map-container">
    <div class="map-bg">
      <svg viewBox="0 0 800 500" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect width="800" height="500" fill="#d4e0d4"/>
        <path d="M100 50 L200 30 L300 60 L400 40 L500 70 L600 50 L700 80 L750 60 L760 150 L720 200 L750 280 L730 350 L680 400 L620 440 L550 460 L450 470 L350 460 L250 450 L180 430 L120 400 L80 350 L60 280 L50 200 L70 150 Z" fill="#c8d8c8" stroke="#b8c8b8" stroke-width="1"/>
        <path d="M150 100 L280 80 L380 110 L450 90 L520 120 L600 100 L650 140 L640 220 L610 280 L590 350 L540 400 L460 430 L370 440 L280 430 L200 410 L150 370 L130 300 L120 220 L130 160 Z" fill="#d8e8d8" stroke="#c8d8c8" stroke-width="1"/>
        <path d="M320 130 L400 120 L450 150 L440 230 L400 280 L340 290 L300 250 L295 180 Z" fill="#c4d4c4" opacity="0.5"/>
        <rect x="280" y="185" width="160" height="1" stroke="#999" stroke-width="0.5" stroke-dasharray="4,4"/>
        <rect x="0" y="185" width="800" height="1" stroke="#aaa" stroke-width="0.3" stroke-dasharray="4,4"/>
      </svg>
      ${pins.map(p=>`<div class="map-pin ${p.cls}" style="left:${p.x};top:${p.y}">
        <div class="pin-dot"><span>📍</span></div>
        <div class="pin-label">${p.label}</div>
      </div>`).join('')}
    </div>
    <div class="map-overlay">
      <h4>🗺 Map Legend</h4>
      <div class="map-legend-item"><div class="legend-dot" style="background:var(--navy);"></div>Single placement</div>
      <div class="map-legend-item"><div class="legend-dot" style="background:var(--gold);"></div>2–4 placements</div>
      <div class="map-legend-item"><div class="legend-dot" style="background:var(--danger);"></div>5+ cluster (batch visit)</div>
      <div style="margin-top:0.875rem;padding-top:0.875rem;border-top:1px solid var(--border);">
        <button class="btn btn-primary btn-sm" style="width:100%;">🧭 Generate Route</button>
      </div>
    </div>
  </div>`;
}

function visitsView() {
  return `
  <div class="filter-bar">
    <select><option>All Visit Types</option><option>Physical</option><option>Virtual</option></select>
    <input type="date">
    <div style="margin-left:auto;"><button class="btn btn-primary btn-sm">+ Schedule New Visit</button></div>
  </div>
  <div class="visit-grid">
    ${[
      {day:'18',mon:'MAR',student:'Jamie Smith',company:'Rolls-Royce, Derby',time:'2:00 PM',type:'🖥 Virtual',tutor:'Dr. Clarke',status:'confirmed'},
      {day:'22',mon:'MAR',student:'Aisha Khan',company:'HSBC, London',time:'11:00 AM',type:'📍 Physical',tutor:'Dr. Clarke',status:'pending'},
      {day:'25',mon:'MAR',student:'Tom Nguyen',company:'Siemens, Sheffield',time:'10:00 AM',type:'📍 Physical',tutor:'Dr. Clarke',status:'confirmed'},
      {day:'28',mon:'MAR',student:'Rachel Lee',company:'PwC, Leeds',time:'3:00 PM',type:'🖥 Virtual',tutor:'Dr. Clarke',status:'pending'},
      {day:'04',mon:'APR',student:'David Martinez',company:'Dyson, Malmesbury',time:'9:30 AM',type:'📍 Physical',tutor:'Dr. Clarke',status:'confirmed'},
      {day:'08',mon:'APR',student:'Hannah Park',company:'NHS Digital, Leeds',time:'2:30 PM',type:'🖥 Virtual',tutor:'Dr. Clarke',status:'pending'},
    ].map(v=>`
    <div class="visit-card">
      <div class="visit-date-block">
        <div class="date-box"><div class="day">${v.day}</div><div class="month">${v.mon}</div></div>
        <div class="visit-date-info"><h4>${v.student}</h4><p>${v.time}</p></div>
      </div>
      <div class="visit-meta">
        <div class="visit-meta-row">🏢 <strong>${v.company}</strong></div>
        <div class="visit-meta-row">${v.type}</div>
        <div class="visit-meta-row">👤 ${v.tutor}</div>
      </div>
      <div style="margin-bottom:1rem;">
        <span class="badge ${v.status==='confirmed'?'badge-approved':'badge-pending'}">${v.status==='confirmed'?'Confirmed':'Pending Confirmation'}</span>
      </div>
      <div class="visit-actions">
        <button class="btn btn-ghost btn-sm" style="flex:1;">Reschedule</button>
        ${v.status==='pending'?`<button class="btn btn-success btn-sm" style="flex:1;">✓ Confirm</button>`:`<button class="btn btn-primary btn-sm" style="flex:1;">Add Notes</button>`}
      </div>
    </div>`).join('')}
  </div>`;
}

function reportsView() {
  const isStudent = currentRole === 'student';
  return `
  <div class="two-col">
    <div class="panel">
      <div class="panel-header"><h3>${isStudent ? 'My Reports' : 'All Submitted Reports'}</h3>${isStudent?'<button class="btn btn-primary btn-sm">+ Submit Report</button>':''}</div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Report</th>${!isStudent?'<th>Student</th>':''}<th>Type</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
            <tr><td><div style="font-weight:500;">Interim Placement Report</div><div style="font-size:0.8125rem;color:var(--muted);">PDF · 3.4 MB</div></td>${!isStudent?'<td>Jamie Smith</td>':''}<td><span class="type-chip">Interim</span></td><td style="font-size:0.875rem;color:var(--muted);">15 Jan 2025</td><td><span class="badge badge-approved">Reviewed</span></td><td><button class="btn btn-ghost btn-sm">⬇ Download</button></td></tr>
            <tr><td><div style="font-weight:500;">Final Placement Report</div><div style="font-size:0.8125rem;color:var(--muted);">Due 30 Apr 2025</div></td>${!isStudent?'<td>Jamie Smith</td>':''}<td><span class="type-chip">Final</span></td><td style="font-size:0.875rem;color:var(--muted);">Not yet submitted</td><td><span class="badge badge-pending">Pending</span></td><td>${isStudent?'<button class="btn btn-primary btn-sm">Submit</button>':'<span style="color:var(--muted);font-size:0.875rem;">Awaiting</span>'}</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="panel">
      <div class="panel-header"><h3>Report Submission Status</h3></div>
      <div class="panel-body">
        ${[['Submitted & Reviewed','34 students','success'],['Submitted — Awaiting Review','6 students','open'],['Overdue','4 students','rejected'],['Upcoming (> 30 days)','4 students','pending']].map(([l,v,c])=>`
        <div style="display:flex;align-items:center;justify-content:space-between;padding:0.875rem 0;border-bottom:1px solid var(--border);">
          <span style="font-size:0.9375rem;color:var(--text);">${l}</span>
          <span class="badge badge-${c}">${v}</span>
        </div>`).join('')}
      </div>
    </div>
  </div>
  ${isStudent?`
  <div class="panel">
    <div class="panel-header"><h3>Submit Final Report</h3></div>
    <div class="panel-body">
      <div class="upload-zone">
        <div style="font-size:3rem;margin-bottom:0.75rem;">📤</div>
        <p><strong>Drop your final report here</strong></p>
        <p>PDF format, maximum 15 MB</p>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1.5rem;">
        <button class="btn btn-ghost">Save Draft</button>
        <button class="btn btn-primary">Submit Final Report →</button>
      </div>
    </div>
  </div>`:''}`
}

function messagesView() {
  return `
  <div class="two-col" style="height:calc(100vh - 200px);gap:1.5rem;">
    <div class="panel" style="margin-bottom:0;display:flex;flex-direction:column;">
      <div class="panel-header"><h3>Conversations</h3></div>
      <div style="overflow-y:auto;flex:1;">
        ${[
          ['EC','Dr. Emily Clarke','Placement Tutor','About your upcoming visit...','10:23 AM',true],
          ['PP','Prajwal Prajwal','Admin User','Your account has been updated.','Yesterday',false],
          ['MH','Mark Henderson','Supervisor','Let me know if you need anything.','Mon',false],
        ].map(([i,n,r,m,t,u])=>`
        <div style="display:flex;align-items:center;gap:0.875rem;padding:1.25rem;border-bottom:1px solid var(--border);cursor:pointer;${u?'background:#f8f9fc;':''}" class="conv-item">
          <div class="avatar" style="${u?'background:var(--navy);':''}">${i}</div>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;"><p style="font-weight:${u?'600':'500'};font-size:0.9375rem;">${n}</p><span style="font-size:0.75rem;color:var(--muted);">${t}</span></div>
            <p style="font-size:0.8125rem;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${r} · ${m}</p>
          </div>
          ${u?`<div style="width:10px;height:10px;border-radius:50%;background:var(--navy);flex-shrink:0;"></div>`:''}
        </div>`).join('')}
      </div>
    </div>

    <div class="panel" style="margin-bottom:0;display:flex;flex-direction:column;">
      <div class="panel-header">
        <div class="avatar-cell">
          <div class="avatar">EC</div>
          <div><h4>Dr. Emily Clarke</h4><p style="font-size:0.8125rem;color:var(--muted);">Placement Tutor · Online</p></div>
        </div>
      </div>
      <div style="flex:1;overflow-y:auto;padding:1.5rem;">
        <div class="message-list">
          <div class="message-item"><div class="avatar">EC</div><div><div class="msg-bubble">Hi Jamie! Just confirming our virtual visit on the 18th March at 2:00 PM. Could you send over a brief agenda beforehand?</div><div class="msg-meta">Dr. Clarke · 10:23 AM</div></div></div>
          <div class="message-item outgoing"><div class="avatar" style="background:var(--gold);color:var(--navy);">JS</div><div><div class="msg-bubble">Hi Dr. Clarke, yes that works perfectly! I'll prepare an agenda covering my projects, progress, and any challenges I've faced.</div><div class="msg-meta">You · 10:45 AM</div></div></div>
          <div class="message-item"><div class="avatar">EC</div><div><div class="msg-bubble">Sounds great. Also, don't forget your final report deadline is 30 April — please let me know if you need an extension.</div><div class="msg-meta">Dr. Clarke · 11:02 AM</div></div></div>
        </div>
      </div>
      <div class="msg-input-bar">
        <input class="msg-input" placeholder="Type a message..." type="text">
        <button class="btn btn-primary">Send →</button>
      </div>
    </div>
  </div>`;
}

// Animate bars on load
setTimeout(() => {
  document.querySelectorAll('.bar[data-h]').forEach(b => { b.style.height = b.getAttribute('data-h'); });
}, 100);