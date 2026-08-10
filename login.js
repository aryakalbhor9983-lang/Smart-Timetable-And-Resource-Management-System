/* =========================================================
   MIT ADT — Authentication UI behaviour
   Shared by admin_login.php and committee_login.php
   Frontend only — never touches PHP session/auth logic.
   ========================================================= */
(function(){
    "use strict";

    document.addEventListener("DOMContentLoaded", function(){
        initParticles();
        initParallax();
        initPasswordToggle();
        initCapsLockWarning();
        initRipple();
        initSubmitGuard();
        autoDismissToasts();
    });

    /* ---------------------------------------------------
       Ambient floating particles / stars on the hero panel
       --------------------------------------------------- */
    function initParticles(){
        var canvas = document.getElementById("heroParticles");
        if(!canvas) return;
        var ctx = canvas.getContext("2d");
        var particles = [];
        var count = window.innerWidth < 860 ? 26 : 46;
        var dpr = window.devicePixelRatio || 1;

        function resize(){
            var rect = canvas.parentElement.getBoundingClientRect();
            canvas.width = rect.width * dpr;
            canvas.height = rect.height * dpr;
            canvas.style.width = rect.width + "px";
            canvas.style.height = rect.height + "px";
            ctx.setTransform(dpr,0,0,dpr,0,0);
            return rect;
        }

        var rect = resize();

        function makeParticle(){
            return {
                x: Math.random() * rect.width,
                y: Math.random() * rect.height,
                r: Math.random() * 1.6 + 0.4,
                vy: Math.random() * 0.12 + 0.03,
                vx: (Math.random() - 0.5) * 0.06,
                a: Math.random() * 0.6 + 0.15,
                twinkle: Math.random() * Math.PI * 2
            };
        }

        for(var i=0;i<count;i++) particles.push(makeParticle());

        var reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

        function draw(){
            ctx.clearRect(0,0,rect.width,rect.height);
            for(var i=0;i<particles.length;i++){
                var p = particles[i];
                p.twinkle += 0.02;
                var alpha = p.a * (0.6 + 0.4 * Math.sin(p.twinkle));
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
                ctx.fillStyle = "rgba(255,255,255," + alpha.toFixed(3) + ")";
                ctx.fill();

                if(!reduceMotion){
                    p.y -= p.vy;
                    p.x += p.vx;
                    if(p.y < -5){ p.y = rect.height + 5; p.x = Math.random() * rect.width; }
                }
            }
            requestAnimationFrame(draw);
        }
        draw();

        window.addEventListener("resize", function(){
            rect = resize();
        });
    }

    /* ---------------------------------------------------
       Subtle mouse parallax on hero glow blobs
       --------------------------------------------------- */
    function initParallax(){
        var hero = document.querySelector(".auth-hero");
        var glows = document.querySelectorAll(".hero-glow");
        if(!hero || !glows.length) return;
        if(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

        hero.addEventListener("mousemove", function(e){
            var rect = hero.getBoundingClientRect();
            var relX = (e.clientX - rect.left) / rect.width - 0.5;
            var relY = (e.clientY - rect.top) / rect.height - 0.5;
            glows.forEach(function(g, idx){
                var strength = (idx + 1) * 8;
                g.style.transform = "translate(" + (relX * strength) + "px," + (relY * strength) + "px)";
            });
        });
        hero.addEventListener("mouseleave", function(){
            glows.forEach(function(g){ g.style.transform = "translate(0,0)"; });
        });
    }

    /* ---------------------------------------------------
       Password show / hide toggle
       --------------------------------------------------- */
    function initPasswordToggle(){
        document.querySelectorAll("[data-toggle-password]").forEach(function(btn){
            btn.addEventListener("click", function(){
                var targetId = btn.getAttribute("data-toggle-password");
                var input = document.getElementById(targetId);
                if(!input) return;
                var showing = input.type === "text";
                input.type = showing ? "password" : "text";
                btn.textContent = showing ? "\uD83D\uDC41" : "\uD83D\uDE48"; /* eye / hidden */
                btn.setAttribute("aria-label", showing ? "Show password" : "Hide password");
            });
        });
    }

    /* ---------------------------------------------------
       Caps Lock warning on password fields
       --------------------------------------------------- */
    function initCapsLockWarning(){
        document.querySelectorAll("[data-caps-check]").forEach(function(input){
            var warning = document.querySelector(input.getAttribute("data-caps-check"));
            if(!warning) return;
            function check(e){
                var on = e.getModifierState && e.getModifierState("CapsLock");
                warning.classList.toggle("visible", !!on);
            }
            input.addEventListener("keyup", check);
            input.addEventListener("keydown", check);
            input.addEventListener("blur", function(){ warning.classList.remove("visible"); });
        });
    }

    /* ---------------------------------------------------
       Ripple effect on the submit button
       --------------------------------------------------- */
    function initRipple(){
        document.querySelectorAll(".auth-submit").forEach(function(btn){
            btn.addEventListener("click", function(e){
                var rect = btn.getBoundingClientRect();
                var circle = document.createElement("span");
                var size = Math.max(rect.width, rect.height);
                circle.className = "ripple";
                circle.style.width = circle.style.height = size + "px";
                circle.style.left = (e.clientX - rect.left - size/2) + "px";
                circle.style.top = (e.clientY - rect.top - size/2) + "px";
                btn.appendChild(circle);
                setTimeout(function(){ circle.remove(); }, 650);
            });
        });
    }

    /* ---------------------------------------------------
       Prevent double submit + show loading state.
       Also does a light client-side "required" shake —
       purely cosmetic, server remains the source of truth.
       --------------------------------------------------- */
    function initSubmitGuard(){
        var form = document.querySelector("form[data-auth-form]");
        if(!form) return;
        var btn = form.querySelector(".auth-submit");
        var overlay = document.getElementById("authOverlay");
        var submitted = false;

        form.addEventListener("submit", function(e){
            var emptyField = null;
            form.querySelectorAll("input[required]").forEach(function(input){
                if(!input.value.trim() && !emptyField) emptyField = input;
            });

            if(emptyField){
                e.preventDefault();
                var group = emptyField.closest(".field-group");
                if(group){
                    group.classList.add("shake");
                    setTimeout(function(){ group.classList.remove("shake"); }, 400);
                }
                emptyField.focus();
                return;
            }

            if(submitted){
                e.preventDefault();
                return;
            }
            submitted = true;

            if(btn){
                btn.classList.add("loading");
                btn.setAttribute("disabled", "disabled");
            }
            if(overlay) overlay.classList.add("visible");
        });
    }

    /* ---------------------------------------------------
       Auto-dismiss server-rendered toasts
       --------------------------------------------------- */
    function autoDismissToasts(){
        document.querySelectorAll(".toast").forEach(function(toast){
            setTimeout(function(){
                toast.classList.add("hide");
                setTimeout(function(){ toast.remove(); }, 320);
            }, 4200);
        });
    }
})();
