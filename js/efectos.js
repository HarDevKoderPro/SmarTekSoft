// Fondo "lluvia de código" de SmartekSoft (mismo efecto que Mini Editor Web).
// Sin dependencias: se pausa cuando la pestaña está oculta o la pantalla no lo necesita.

const reducido = false; // la lluvia en movimiento es parte del diseño
const ALFABETO = "01ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
const TAMANO = 16;
const FPS = 30;

export function iniciarMatrix(canvas) {
  const ctx = canvas.getContext("2d");
  let gotas = [];
  let activo = false, rafId = 0, ultimo = 0;

  function medir() {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    gotas = Array.from({ length: Math.ceil(canvas.width / TAMANO) }).fill(1);
    ctx.fillStyle = "#000";
    ctx.fillRect(0, 0, canvas.width, canvas.height);
  }

  function dibujar() {
    ctx.fillStyle = "rgba(0, 0, 0, 0.05)";
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = "#0F0";
    ctx.font = `${TAMANO}px monospace`;
    for (let i = 0; i < gotas.length; i++) {
      ctx.fillText(ALFABETO.charAt(Math.floor(Math.random() * ALFABETO.length)), i * TAMANO, gotas[i] * TAMANO);
      if (gotas[i] * TAMANO > canvas.height && Math.random() > 0.975) gotas[i] = 0;
      gotas[i]++;
    }
  }

  function cuadro(t) {
    rafId = requestAnimationFrame(cuadro);
    if (t - ultimo < 1000 / FPS) return;
    ultimo = t;
    dibujar();
  }

  function activar() {
    canvas.style.display = "block";
    if (activo) return;
    activo = true;
    medir();
    if (reducido) {
      // Una imagen fija de la lluvia, sin movimiento.
      for (let i = 0; i < 90; i++) dibujar();
    } else if (!document.hidden) {
      rafId = requestAnimationFrame(cuadro);
    }
  }

  function pausar() {
    activo = false;
    cancelAnimationFrame(rafId);
    canvas.style.display = "none";
  }

  document.addEventListener("visibilitychange", () => {
    cancelAnimationFrame(rafId);
    if (activo && !document.hidden && !reducido) rafId = requestAnimationFrame(cuadro);
  });

  let temporizador;
  window.addEventListener("resize", () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => activo && medir(), 150);
  });

  return {
    activar,
    pausar,
    // Destello breve de la lluvia al conceder el acceso.
    pulso() {
      if (reducido) return;
      canvas.classList.add("campo-pulso");
      setTimeout(() => canvas.classList.remove("campo-pulso"), 700);
    },
  };
}
