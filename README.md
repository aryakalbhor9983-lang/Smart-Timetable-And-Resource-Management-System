# Smart-Timetable-And-Resource-Management-System
A web-based timetable automation system developed during a 4-week internship at MIT ADT University. Built with PHP, MySQL, Bootstrap to manage 68+ divisions, 200+ faculty, and 3,400+ timetable entries with conflict detection, workload analytics, and PDF export. Eliminates manual spreadsheet errors through centralized 3NF database architecture.
About The Project
The Smart Timetable & Resource Management System is a full-stack academic scheduling platform developed for the School of Computing, MIT Art, Design & Technology University, Pune.

Traditional academic scheduling often depends heavily on large spreadsheets and manually maintained timetable files.

This project transforms that information into a centralized, searchable and analytics-driven web platform.

The system brings together:

Timetables
    ↓
Faculty Workload
    ↓
Classrooms & Labs
    ↓
Resource Availability
    ↓
Conflict Detection
    ↓
Academic Analytics
    ↓
Institutional Reporting
Instead of treating a timetable as only a static document, the platform treats scheduling data as a connected academic information system.

Core Capabilities:
Division Timetables
Access complete division-wise academic schedules with filtering based on:
Academic year
Semester
Department
Programme
Year
Division
Timetables maintain the structured format required for institutional academic use.

Faculty Timetables
Generate individual faculty schedules directly from centralized timetable records.
Faculty views provide:
Weekly teaching schedule
Assigned subjects
Classroom information
Division allocation
Weekly workload

Physical Resource Scheduling
View dedicated schedules for classrooms and laboratories.
The system provides insight into:
Resource occupancy
Weekly utilization
Used slots
Available slots
Classroom scheduling

Free Resource Discovery
Quickly discover classrooms and other resources that are available during particular academic slots.
This eliminates the need to manually compare dozens of division timetables.

Faculty Free Timeslots
Determine faculty availability directly from their existing academic schedules.
Useful for:
Meetings
Mentoring
Academic coordination
Additional sessions
Faculty planning

Timetable / PDF Export
Generate print-friendly timetable layouts suitable for:
PDF export
Official circulation
Department documentation
Academic records
Faculty distribution

🔥 Slot Usage Heatmap
The system analyzes how many divisions are teaching simultaneously during every academic slot.

This makes it possible to identify:

🔥 Busiest Slots

Periods with maximum academic activity.

❄️ Lightest Slots

Periods with comparatively lower academic activity.

This information can assist with:

Resource planning
Classroom allocation
Scheduling optimization
Infrastructure analysis

Application Modules
Smart Timetable System
│
├── 🏠 Dashboard
│
├── 📅 Timetable Management
│   ├── Division Timetable
│   ├── Faculty Timetable
│   └── Physical Resource Timetable
│
├── 🔍 Availability
│   ├── Free Physical Resources
│   └── Faculty Free Timeslots
│
├── 📊 Analytics
│   ├── Overview
│   ├── Faculty Analytics
│   ├── Room Utilization
│   ├── Conflict Detection
│   ├── Completeness
│   ├── Subject Analysis
│   ├── Workload vs Actual
│   └── Slot Heatmap
│
├── 📥 Data Management
│   ├── Faculty Master Import
│   ├── Subject Master Import
│   ├── Physical Resource Import
│   └── Timetable Import
│
├── 📄 Reporting
│   ├── Faculty Workload Report
│   └── Timetable PDF Export
│
└── 🔐 Administration
    ├── Admin Login
    └── Committee Login

    Future Roadmap
Current Platform
       │
       ├──► Constraint-Based Timetable Generation
       │
       ├──► Automatic Conflict Resolution
       │
       ├──► Intelligent Room Allocation
       │
       ├──► Faculty Workload Optimization
       │
       ├──► Role-Based Access Control
       │
       ├──► Timetable Change Notifications
       │
       ├──► REST API
       │
       ├──► Historical Scheduling Analytics
       │
       ├──► Utilization Forecasting
       │
       └──► Advanced Scheduling Optimization
Potential future development includes automated timetable generation using scheduling constraints and optimization algorithms.
