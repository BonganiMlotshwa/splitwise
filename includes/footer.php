    </main>
    
    <!-- Compact Footer -->
    <footer class="footer">
      <div class="container-fluid">
        <div class="row align-items-center py-2">
          <div class="col-12 text-center">
            <div class="d-flex align-items-center justify-content-center gap-3 flex-wrap">
              <span class="footer-brand">
                <i class="bi bi-box-seam me-1"></i>
                <strong>FTM IT PROPERTY RECORDS</strong>
              </span>
              <span class="footer-info">© <?php echo date('Y'); ?> - Professional IT Asset Management</span>
              <?php if (!empty($_SESSION['user_id'])): ?>
              <span class="user-info">
                <i class="bi bi-person-circle me-1"></i>
                <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>
              </span>
              <?php endif; ?>
              <span class="version-info">
                <i class="bi bi-code-square me-1"></i>v2.0
              </span>
            </div>
          </div>
        </div>
      </div>
    </footer>

    <style>
    .footer {
      background: linear-gradient(135deg, #059669 0%, #10b981 100%); /* Match navbar green gradient */
      color: white;
      margin-top: auto;
      box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
      font-size: 0.85rem;
      margin-left: 0; /* Full width footer */
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      z-index: 1020;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    .footer-brand {
      font-weight: 600;
      color: white;
      white-space: nowrap;
    }

    .footer-info {
      opacity: 0.9;
      font-size: 0.8rem;
      color: rgba(255, 255, 255, 0.8);
    }

    .user-info, .version-info {
      font-size: 0.8rem;
      opacity: 0.95;
      white-space: nowrap;
      color: #f0f9ff; /* Light blue accent for contrast */
    }

    /* Ensure footer stays at bottom and body has proper spacing */
    body {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      padding-bottom: 60px; /* Space for fixed footer */
    }

    main {
      flex: 1;
    }

    /* Mobile adjustments */
    @media (max-width: 767px) {
      .col-12.text-center {
        text-align: center !important;
      }
      
      .d-flex.gap-3 {
        justify-content: center !important;
      }
    }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!empty($_SESSION['user_id'])): ?>
    <script>
    // Hide greeting after 3 seconds on first login
    (function(){
      const greeting = document.getElementById('userGreeting');
      const justLoggedIn = <?php echo isset($_SESSION['just_logged_in']) ? 'true' : 'false'; ?>;
      
      if (greeting) {
        if (justLoggedIn) {
          // Show for 3 seconds then fade out
          setTimeout(() => {
            greeting.style.transition = 'opacity 0.5s';
            greeting.style.opacity = '0';
            setTimeout(() => { 
              greeting.style.display = 'none'; 
            }, 500);
          }, 3000);
        } else {
          // Hide immediately on subsequent pages
          greeting.style.display = 'none';
        }
      }
    })();
    </script>
    <?php unset($_SESSION['just_logged_in']); ?>
    <div class="modal fade" id="idleModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Are you still there?</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>Your session will expire in <strong id="idleCountdown">60</strong> seconds due to inactivity.</p>
          </div>
          <div class="modal-footer">
            <button type="button" id="staySignedInBtn" class="btn btn-primary">Stay Signed In</button>
          </div>
        </div>
      </div>
    </div>
    <script>
    (function(){
      const role = <?php echo json_encode($_SESSION['user_role'] ?? 'user'); ?>;
      const adminTimeout = <?php echo (int)SESSION_TIMEOUT_ADMIN; ?>; // seconds
      const userTimeout = <?php echo (int)SESSION_TIMEOUT_USER; ?>; // seconds
      const timeoutSec = role === 'admin' ? adminTimeout : userTimeout;
      const warnBefore = 60; // warn 60s before expiry
      const modalEl = document.getElementById('idleModal');
      if (!modalEl) return;
      const bsModal = new bootstrap.Modal(modalEl, {backdrop: 'static', keyboard: false});
      const countdownEl = document.getElementById('idleCountdown');
      const stayBtn = document.getElementById('staySignedInBtn');

      let lastActivity = Date.now();
      let warnTimer = null;
      let tickTimer = null;
      let isModalShown = false;

      const resetActivity = () => { 
        lastActivity = Date.now(); 
        // If modal is showing and user is active, hide it and restart timer
        if (isModalShown) {
          bsModal.hide();
          isModalShown = false;
          if (tickTimer) clearInterval(tickTimer);
          scheduleWarning();
        }
      };

      // Listen to more user activity events
      ['mousemove','mousedown','mouseup','keydown','keyup','scroll','click','touchstart','touchmove','touchend','focus','blur','resize','input','change','submit'].forEach(ev => {
        document.addEventListener(ev, resetActivity, {passive: true, capture: true});
      });

      // Also listen on window for broader coverage
      ['focus','blur','beforeunload'].forEach(ev => {
        window.addEventListener(ev, resetActivity, {passive: true});
      });

      function scheduleWarning(){
        if (warnTimer) clearTimeout(warnTimer);
        if (tickTimer) clearInterval(tickTimer);
        
        const now = Date.now();
        const elapsed = Math.floor((now - lastActivity) / 1000);
        const untilExpire = Math.max(0, timeoutSec - elapsed);
        const untilWarn = Math.max(0, untilExpire - warnBefore);
        
        if (untilWarn <= 0 && untilExpire > 0) {
          showWarning(untilExpire);
        } else if (untilExpire <= 0) {
          doLogout();
        } else {
          warnTimer = setTimeout(() => {
            const newNow = Date.now();
            const newElapsed = Math.floor((newNow - lastActivity) / 1000);
            const newRemaining = Math.max(0, timeoutSec - newElapsed);
            if (newRemaining > 0) {
              showWarning(newRemaining);
            } else {
              doLogout();
            }
          }, untilWarn * 1000);
        }
      }

      function showWarning(secondsLeft){
        if (isModalShown) return; // Prevent multiple modals
        
        let remaining = secondsLeft;
        if (remaining <= 0) { 
          doLogout(); 
          return; 
        }
        
        isModalShown = true;
        countdownEl.textContent = remaining;
        bsModal.show();
        
        tickTimer = setInterval(() => {
          const now = Date.now();
          const elapsed = Math.floor((now - lastActivity) / 1000);
          remaining = Math.max(0, timeoutSec - elapsed);
          countdownEl.textContent = remaining;
          
          if (remaining <= 0) { 
            clearInterval(tickTimer); 
            doLogout(); 
          }
        }, 1000);
      }

      function doLogout(){
        if (warnTimer) clearTimeout(warnTimer);
        if (tickTimer) clearInterval(tickTimer);
        window.location.href = <?php echo json_encode(BASE_PATH . 'auth/logout.php'); ?>;
      }

      // Handle modal events
      modalEl.addEventListener('hidden.bs.modal', () => {
        isModalShown = false;
        if (tickTimer) clearInterval(tickTimer);
      });

      if (stayBtn) {
        stayBtn.addEventListener('click', () => {
          lastActivity = Date.now();
          isModalShown = false;
          bsModal.hide();
          if (tickTimer) clearInterval(tickTimer);
          scheduleWarning();
        });
      }

      // Start the timeout system
      scheduleWarning();
      
      // Debug logging (remove in production)
      console.log('Session timeout initialized:', {
        role: role,
        timeoutSec: timeoutSec,
        warnBefore: warnBefore
      });
    })();
    </script>
    <?php endif; ?>
  </body>
</html>
