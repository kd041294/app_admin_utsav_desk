<?php if (!defined('UTSAVDESK_ADMIN_HEADER_INCLUDED')) { http_response_code(404); exit; } ?>
<!doctype html>

<html lang="en">

<?php renderAdminHead($pageTitle, $pageStylesheets); ?>


<body class="admin-body">


    <?php require __DIR__ . '/../components/sidebar.php'; ?>


    <!-- =========================================================
     MAIN
     ========================================================= -->

    <div class="admin-main">


        <!-- =====================================================
         TOPBAR
         ===================================================== -->

        <header class="admin-topbar">

            <div class="topbar-left">

                <button
                    type="button"
                    class="mobile-menu-button"
                    id="sidebarOpen">
                    <i class="bi bi-list"></i>
                </button>


                <div class="topbar-title">

                    <h1>
                        Settings
                    </h1>

                    <span>
                        Platform configuration
                    </span>

                </div>

            </div>


            <div class="topbar-actions">


                <a class="topbar-action-btn" href="../activity/" aria-label="View activity logs"><i class="bi bi-clock-history"></i></a>


                <div class="dropdown">

                    <button
                        type="button"
                        class="topbar-profile"
                        data-bs-toggle="dropdown">

                        <div class="topbar-avatar">
                            <?= htmlspecialchars(strtoupper(substr($adminName, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                        </div>


                        <div class="topbar-profile-info d-none d-md-block">

                            <strong>
                                <?= htmlspecialchars($adminName) ?>
                            </strong>

                            <span>
                                <?= htmlspecialchars(str_replace('_', ' ', ucfirst(strtolower($adminRole)))) ?>
                            </span>

                        </div>


                        <i class="bi bi-chevron-down"></i>

                    </button>


                    <ul class="dropdown-menu dropdown-menu-end">

                        <li>

                            <span class="dropdown-item-text"><?= htmlspecialchars($admin['EMAIL'], ENT_QUOTES, 'UTF-8') ?></span>

                        </li>


                        <li>

                            <a
                                class="dropdown-item"
                                href="../settings/">
                                <i class="bi bi-gear me-2"></i>
                                Settings
                            </a>

                        </li>


                        <li>
                            <hr class="dropdown-divider">
                        </li>


                        <li>

                            <form method="post" action="<?= htmlspecialchars(APP_BASE_URL . '/logout.php', ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="dropdown-item text-danger border-0 bg-transparent text-start w-100">
                                    <i class="bi bi-box-arrow-right me-2"></i>
                                    Sign Out
                                </button>
                            </form>

                        </li>

                    </ul>

                </div>

            </div>

        </header>


        <!-- =====================================================
         CONTENT
         ===================================================== -->

        