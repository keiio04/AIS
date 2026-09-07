<?php
// ============================================================
// access_control.php — Instructor "view student company" mode
//
// When an Instructor opens a student's company to review their
// work, we swap $_SESSION['active_company_id'] to that company's
// id (every existing page already reads that session var, so
// reports/ledgers "just work" without changes). We remember the
// instructor's own active company so it can be restored, and we
// flag the session as view-only so POST actions across the app
// can refuse to save anything while browsing a student's books.
// ============================================================

function is_view_only(): bool {
    return !empty($_SESSION['instructor_viewing']);
}

// Call this at the top of any POST handler that creates/edits/
// deletes data. Aborts the request if we're in instructor
// view-only mode, so a student's company can never be modified
// by an instructor who is just reviewing it.
function block_if_view_only(): void {
    if (is_view_only()) {
        http_response_code(403);
        die('<div style="padding:2rem;font-family:sans-serif;max-width:520px;margin:3rem auto;text-align:center;">
                <h2 style="color:#991b1b;">View-Only Mode</h2>
                <p style="color:#4b5563;">You are viewing a student\'s company as an instructor. Editing, adding, and deleting records is disabled while in this mode.</p>
                <p><a href="javascript:history.back()">&larr; Go back</a></p>
            </div>');
    }
}

// Switches the current (instructor) session into read-only view
// of a specific student's company.
function start_instructor_view(int $companyId, string $studentName, string $companyName): void {
    if (!isset($_SESSION['instructor_own_company_id'])) {
        $_SESSION['instructor_own_company_id'] = $_SESSION['active_company_id'] ?? null;
    }
    $_SESSION['instructor_viewing'] = true;
    $_SESSION['instructor_viewing_student_name'] = $studentName;
    $_SESSION['instructor_viewing_company_name'] = $companyName;
    $_SESSION['active_company_id'] = $companyId;
}

// Restores the instructor's own active company and clears the
// view-only flags.
function end_instructor_view(): void {
    $_SESSION['active_company_id'] = $_SESSION['instructor_own_company_id'] ?? null;
    unset(
        $_SESSION['instructor_viewing'],
        $_SESSION['instructor_viewing_student_name'],
        $_SESSION['instructor_viewing_company_name'],
        $_SESSION['instructor_own_company_id']
    );
}