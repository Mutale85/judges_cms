<?php

declare(strict_types=1);

use JC\Controller\AuditController;
use JC\Controller\AuthController;
use JC\Controller\CaseController;
use JC\Controller\DashboardController;
use JC\Controller\DocumentController;
use JC\Controller\HearingController;
use JC\Controller\InternalController;
use JC\Controller\NoteController;
use JC\Controller\RecordingController;
use JC\Controller\StaffController;
use JC\Controller\TaskController;
use JC\Core\Router;

$router = new Router();
$guest = ['auth' => 'none'];
$service = ['auth' => 'service', 'csrf' => false];

// Authentication
$router->get('/login', [AuthController::class, 'showLogin'], $guest);
$router->post('/login', [AuthController::class, 'login'], $guest);
$router->get('/login/mfa', [AuthController::class, 'showMfa'], $guest);
$router->post('/login/mfa', [AuthController::class, 'verifyMfa'], $guest);
$router->get('/mfa/setup', [AuthController::class, 'showMfaSetup'], $guest);
$router->post('/mfa/setup', [AuthController::class, 'completeMfaSetup'], $guest);
$router->post('/logout', [AuthController::class, 'logout'], $guest);
$router->get('/account/password', [AuthController::class, 'showPassword']);
$router->post('/account/password', [AuthController::class, 'changePassword']);

// Dashboard
$router->get('/', [DashboardController::class, 'index']);
$router->post('/chambers/switch', [DashboardController::class, 'switchChambers']);

// Cases
$router->get('/cases', [CaseController::class, 'index']);
$router->get('/cases/new', [CaseController::class, 'create']);
$router->post('/cases', [CaseController::class, 'store']);
$router->get('/cases/{id}', [CaseController::class, 'show']);
$router->get('/cases/{id}/edit', [CaseController::class, 'edit']);
$router->post('/cases/{id}', [CaseController::class, 'update']);
$router->post('/cases/{id}/status', [CaseController::class, 'status']);
$router->post('/cases/{id}/seal', [CaseController::class, 'seal']);
$router->post('/cases/{id}/parties', [CaseController::class, 'addParty']);
$router->post('/cases/{id}/parties/{party}/delete', [CaseController::class, 'removeParty']);
$router->post('/cases/{id}/assign', [CaseController::class, 'assign']);
$router->post('/cases/{id}/assign/{user}/delete', [CaseController::class, 'unassign']);

// Documents
$router->post('/cases/{id}/documents', [DocumentController::class, 'store']);
$router->get('/documents/{id}/download', [DocumentController::class, 'download']);
$router->post('/documents/{id}/verify', [DocumentController::class, 'verify']);

// Private judicial notes
$router->post('/cases/{id}/notes', [NoteController::class, 'store']);
$router->post('/notes/{id}', [NoteController::class, 'update']);

// Hearings & calendar
$router->get('/calendar', [HearingController::class, 'calendar']);
$router->get('/calendar/cause-list', [HearingController::class, 'causeList']);
$router->post('/cases/{id}/hearings', [HearingController::class, 'store']);
$router->get('/hearings/{id}', [HearingController::class, 'show']);
$router->post('/hearings/{id}/confirm', [HearingController::class, 'confirm']);
$router->post('/hearings/{id}/adjourn', [HearingController::class, 'adjourn']);
$router->post('/hearings/{id}/complete', [HearingController::class, 'complete']);

// Recordings & transcripts
$router->post('/hearings/{id}/recordings', [RecordingController::class, 'start']);
$router->get('/recordings/{id}/capture', [RecordingController::class, 'capture']);
$router->post('/recordings/{id}/token', [RecordingController::class, 'token']);
$router->get('/recordings/{id}', [RecordingController::class, 'show']);
$router->get('/recordings/{id}/audio', [RecordingController::class, 'audio']);
$router->get('/recordings/{id}/export', [RecordingController::class, 'export']);
$router->post('/recordings/{id}/segments', [RecordingController::class, 'addSegment']);
$router->post('/recordings/{id}/segments/{segment}', [RecordingController::class, 'editSegment']);
$router->post('/recordings/{id}/bookmarks', [RecordingController::class, 'bookmark']);
$router->post('/recordings/{id}/certify', [RecordingController::class, 'certify']);
$router->post('/recordings/{id}/verify', [RecordingController::class, 'verify']);
$router->post('/recordings/{id}/retranscribe', [RecordingController::class, 'retranscribe']);

// Tasks
$router->get('/tasks', [TaskController::class, 'index']);
$router->post('/tasks', [TaskController::class, 'store']);
$router->post('/tasks/{id}/status', [TaskController::class, 'status']);

// Staff & permissions (judge)
$router->get('/staff', [StaffController::class, 'index']);
$router->post('/staff', [StaffController::class, 'store']);
$router->get('/staff/{id}', [StaffController::class, 'edit']);
$router->post('/staff/{id}', [StaffController::class, 'update']);
$router->post('/staff/{id}/reset-password', [StaffController::class, 'resetPassword']);
$router->post('/staff/{id}/reset-mfa', [StaffController::class, 'resetMfa']);

// Audit
$router->get('/audit', [AuditController::class, 'index']);
$router->post('/audit/verify', [AuditController::class, 'verify']);

// Transcription service callbacks (HMAC-signed, TLS)
$router->post('/internal/recordings/{id}/started', [InternalController::class, 'started'], $service);
$router->post('/internal/recordings/{id}/segments', [InternalController::class, 'segments'], $service);
$router->post('/internal/recordings/{id}/bookmarks', [InternalController::class, 'bookmark'], $service);
$router->post('/internal/recordings/{id}/finalize', [InternalController::class, 'finalize'], $service);
$router->post('/internal/recordings/{id}/transcribed', [InternalController::class, 'transcribed'], $service);
$router->post('/internal/recordings/{id}/failed', [InternalController::class, 'failed'], $service);

return $router;
