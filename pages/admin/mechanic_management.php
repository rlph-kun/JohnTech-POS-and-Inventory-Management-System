<?php
/*
======================================================================
  Page: Mechanic Management
  Description: Admin interface to add, edit, delete mechanics, and
               toggle presence status. Groups mechanics by branch.
  Notes:
  - Functionality and UI/UX remain unchanged.
  - Structured section headers for maintainability.
======================================================================
*/
include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);

// ====================================================================
// Handle Add/Edit/Delete
// ====================================================================
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Mechanic
    if (isset($_POST['add_mechanic'])) {
        $name = trim($_POST['name']);
        $branch_id = intval($_POST['branch_id']);
        $contact = trim($_POST['contact']);
        $email = trim($_POST['email']);
        
        // Validate contact: must be exactly 11 digits and only numbers
        if (!preg_match('/^\d{11}$/', $contact)) {
            $errors[] = 'Contact must be exactly 11 digits and contain only numbers.';
        }
        
        if ($name && $branch_id && $contact && empty($errors)) {
            $stmt = $conn->prepare("INSERT INTO mechanics (name, branch_id, contact, email, is_present) VALUES (?, ?, ?, ?, 0)");
            $stmt->bind_param('siss', $name, $branch_id, $contact, $email);
            if ($stmt->execute()) {
                $success = 'Mechanic added successfully!';
            } else {
                $errors[] = 'Error adding mechanic.';
            }
            $stmt->close();
        } else if (empty($errors)) {
            $errors[] = 'Name, branch, and contact are required.';
        }
    }
    
    // Edit Mechanic
    if (isset($_POST['edit_mechanic'])) {
        $id = intval($_POST['mechanic_id']);
        $name = trim($_POST['name']);
        $branch_id = intval($_POST['branch_id']);
        $contact = trim($_POST['contact']);
        $email = trim($_POST['email']);
        
        // Validate contact: must be exactly 11 digits and only numbers
        if (!preg_match('/^\d{11}$/', $contact)) {
            $errors[] = 'Contact must be exactly 11 digits and contain only numbers.';
        }
        
        if ($id && $name && $branch_id && $contact && empty($errors)) {
            $stmt = $conn->prepare("UPDATE mechanics SET name=?, branch_id=?, contact=?, email=? WHERE id=?");
            $stmt->bind_param('sissi', $name, $branch_id, $contact, $email, $id);
            if ($stmt->execute()) {
                $success = 'Mechanic updated successfully!';
            } else {
                $errors[] = 'Error updating mechanic.';
            }
            $stmt->close();
        } else if (empty($errors)) {
            $errors[] = 'Name, branch, and contact are required.';
        }
    }
    
    // Delete Mechanic
    if (isset($_POST['delete_mechanic'])) {
        $id = intval($_POST['delete_mechanic']);
        $stmt = $conn->prepare("DELETE FROM mechanics WHERE id=?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $success = 'Mechanic deleted successfully!';
        } else {
            $errors[] = 'Error deleting mechanic.';
        }
        $stmt->close();
    }

    // Toggle Presence
    if (isset($_POST['toggle_presence'])) {
        $id = intval($_POST['mechanic_id']);
        $is_present = intval($_POST['is_present']);
        $stmt = $conn->prepare("UPDATE mechanics SET is_present=? WHERE id=?");
        $stmt->bind_param('ii', $is_present, $id);
        if ($stmt->execute()) {
            $success = 'Mechanic presence updated!';
        } else {
            $errors[] = 'Error updating mechanic presence.';
        }
        $stmt->close();
    }
}

// ====================================================================
// Get branches for dropdown
// ====================================================================
$branches = [];
$branch_res = $conn->query("SELECT id, name FROM branches");
while ($row = $branch_res->fetch_assoc()) {
    $branches[$row['id']] = $row['name'];
}

// ====================================================================
// Get mechanics with branch names
// ====================================================================
$mechanics_by_branch = [];
$res = $conn->query("SELECT m.*, b.name as branch_name FROM mechanics m LEFT JOIN branches b ON m.branch_id = b.id ORDER BY b.name ASC, m.name ASC");
while ($row = $res->fetch_assoc()) {
    $mechanics_by_branch[$row['branch_name']][] = $row;
}
?>
<?php
// ====================================================================
// HTML OUTPUT
// ====================================================================
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JohnTech Management System - Mechanic Management</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css?v=<?= time() ?>">
</head>
<body style="margin: 0 !important; padding: 0 !important;">
<?php include '../../includes/admin_sidebar.php'; ?>

<div class="container-fluid" style="margin-left: 250px !important; margin-top: 0 !important; padding: 0.5rem !important; padding-top: 0 !important; background: #f8fafc !important; min-height: 100vh !important; position: relative !important; z-index: 1 !important;">
    <div class="main-content" style="position: relative !important; z-index: 2 !important; margin-top: 0 !important; padding-top: 0.5rem !important;">
        <!-- Top Header Area -->
        <div class="d-flex justify-content-between align-items-center mb-3" style="background: #ffffff; padding: 0.75rem 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 0 !important;">
            <div>
                <h1 class="mb-0" style="color: #1565c0; font-size: 1.25rem; font-weight: 600;">
                    <i class="bi bi-gear me-2"></i>JohnTech Management System
                </h1>
            </div>
            <div class="text-end">
                <div style="color: #64748b; font-size: 0.85rem;">
                    <i class="bi bi-person-circle me-1"></i>Welcome, Admin
                </div>
                <div style="color: #64748b; font-size: 0.8rem;">
                    <i class="bi bi-clock me-1"></i><?= date('h:i A') ?>
                </div>
            </div>
        </div>
        
        <h2 class="mb-4" style="color: #1a202c !important; font-size: 1.75rem;"><i class="bi bi-people-fill me-2"></i>Mechanic Management</h2>
        
        <div class="content-card" style="background: #ffffff !important; border-radius: 12px !important; padding: 1.5rem !important; margin-bottom: 1.5rem !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important; position: relative !important; z-index: 3 !important;">
            <?php if ($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger"><?= implode('<br>', $errors) ?></div>
            <?php endif; ?>

            <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add New Mechanic
            </button>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Name</th>
                            <th>Branch</th>
                            <th>Contact</th>
                            <th>Email</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mechanics_by_branch as $branch_name => $mechanics): ?>
                            <tr class="table-primary">
                                <td colspan="6"><strong><?= htmlspecialchars($branch_name) ?></strong></td>
                            </tr>
                            <?php foreach ($mechanics as $mechanic): ?>
                                <tr>
                                    <td><?= htmlspecialchars($mechanic['name']) ?></td>
                                    <td><?= htmlspecialchars($mechanic['branch_name']) ?></td>
                                    <td><?= htmlspecialchars($mechanic['contact']) ?></td>
                                    <td><?= htmlspecialchars($mechanic['email']) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $mechanic['id'] ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <form method="post" style="display:inline-block" onsubmit="return confirm('Are you sure you want to delete this mechanic?');">
                                            <input type="hidden" name="delete_mechanic" value="<?= $mechanic['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="bi bi-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modals - Generate after the main table -->
<?php foreach ($mechanics_by_branch as $branch_name => $mechanics): ?>
    <?php foreach ($mechanics as $mechanic): ?>
        <div class="modal fade" id="editModal<?= $mechanic['id'] ?>" tabindex="-1" aria-labelledby="editModalLabel<?= $mechanic['id'] ?>" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="post">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editModalLabel<?= $mechanic['id'] ?>">Edit Mechanic - <?= htmlspecialchars($mechanic['name']) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="mechanic_id" value="<?= $mechanic['id'] ?>">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Name <span class="text-danger">*</span></label>
                                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($mechanic['name']) ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Branch <span class="text-danger">*</span></label>
                                        <select name="branch_id" class="form-select" required>
                                            <?php foreach ($branches as $id => $name): ?>
                                                <option value="<?= $id ?>" <?= $id == $mechanic['branch_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($name) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Contact <span class="text-danger">*</span></label>
                                        <input type="text" name="contact" class="form-control" value="<?= htmlspecialchars($mechanic['contact']) ?>" required pattern="\d{11}" maxlength="11" minlength="11" inputmode="numeric" title="Contact must be exactly 11 digits">
                                        <small class="text-muted">Must be exactly 11 digits</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($mechanic['email']) ?>">
                                        <small class="text-muted">Optional</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x-circle me-1"></i>Cancel
                            </button>
                            <button type="submit" name="edit_mechanic" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Mechanic</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select" required>
                            <?php foreach ($branches as $id => $name): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contact <span class="text-danger">*</span></label>
                        <input type="text" name="contact" class="form-control" required pattern="\d{11}" maxlength="11" minlength="11" inputmode="numeric" title="Contact must be exactly 11 digits" placeholder="09123456789">
                        <small class="text-muted">Must be exactly 11 digits</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="mechanic@example.com">
                        <small class="text-muted">Optional</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="submit" name="add_mechanic" class="btn btn-success">
                        <i class="bi bi-plus-circle me-1"></i>Add Mechanic
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<script>
window.addEventListener('load', function() {
  document.body.classList.add('loaded');
});
</script>
</body>
</html>
