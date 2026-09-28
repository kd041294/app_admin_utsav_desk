<?php if (!defined('UTSAVDESK_ADMIN_HEADER_INCLUDED')) { http_response_code(403); exit; } ?>
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
                        Analytics
                    </h1>

                    <span>
                        Platform performance and business intelligence
                    </span>

                </div>

            </div>


            <div class="topbar-actions">


                <button
                    type="button"
                    class="topbar-action-btn d-none d-md-flex">
                    <i class="bi bi-search"></i>
                </button>


                <button
                    type="button"
                    class="topbar-action-btn notification-button">
                    <i class="bi bi-bell"></i>
                    <span class="notification-dot"></span>
                </button>


                <div class="dropdown">

                    <button
                        type="button"
                        class="topbar-profile"
                        data-bs-toggle="dropdown">

                        <div class="topbar-avatar">
                            KJ
                        </div>


                        <div class="topbar-profile-info d-none d-md-block">

                            <strong>
                                <?= htmlspecialchars($admin['name']) ?>
                            </strong>

                            <span>
                                Super Admin
                            </span>

                        </div>


                        <i class="bi bi-chevron-down"></i>

                    </button>


                    <ul class="dropdown-menu dropdown-menu-end">

                        <li>

                            <a
                                class="dropdown-item"
                                href="#">
                                <i class="bi bi-person me-2"></i>
                                My Profile
                            </a>

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

        
