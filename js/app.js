import { iniciarMatrix } from "./efectos.js?v=20261122-21";

const VERSION = "20261122-21";
const routes = {
  home: document.getElementById("home-screen"),
  clases: document.getElementById("clases-screen"),
  apps: document.getElementById("apps-screen"),
  tips: document.getElementById("app"),
  drive: document.getElementById("drive-screen"),
};
const routeByHash = { "#home": "home", "#clases": "clases", "#apps": "apps", "#tips": "tips", "#drive": "drive" };

const gate = document.getElementById("login-modal");
const campo = iniciarMatrix(document.getElementById("campo"));
let tipsModuleLoaded = false;
let temporizadorEstado = 0;

const $ = (id) => document.getElementById(id);

/* ---------------------------------------------------------------- navegación */

async function asegurarModuloTips() {
  if (tipsModuleLoaded) return;
  try {
    await import(`./script.js?v=${VERSION}`);
    tipsModuleLoaded = true;
  } catch (error) {
    console.error("No se pudo cargar el modulo Tips:", error);
  }
}

function irA(routeName) {
  Object.values(routes).forEach((el) => el.classList.add("screen-hidden"));
  routes[routeName].classList.remove("screen-hidden");
  if (routeName === "home" || routeName === "apps" || routeName === "clases") {
    campo.activar();
    iniciarEstado();
  } else {
    campo.pausar();
    detenerEstado();
  }
  if (routeName === "tips") asegurarModuloTips();
}

function resolverRuta() {
  if (!window.piaUsuario) {
    Object.values(routes).forEach((el) => el.classList.add("screen-hidden"));
    detenerEstado();
    mostrarAcceso();
    return;
  }
  gate.classList.add("hidden");
  irA(routeByHash[window.location.hash] || "home");
}

function navegar(routeName) {
  if (window.location.hash === `#${routeName}`) resolverRuta();
  else window.location.hash = routeName;
}

/* -------------------------------------------------------------------- sesión */

function actualizarInterfazSesion() {
  document.dispatchEvent(new CustomEvent("pia-sesion-actualizada", { detail: window.piaUsuario }));
}

async function consultarSesion() {
  try {
    const response = await fetch("api/auth.php");
    const json = await response.json();
    window.piaUsuario = json.success ? json.data?.usuario || null : null;
  } catch (error) {
    console.error("Error al consultar la sesion:", error);
    window.piaUsuario = null;
  }
  actualizarInterfazSesion();
  resolverRuta();
}

async function cerrarSesion() {
  try {
    const response = await fetch("api/auth.php", {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ accion: "logout" }),
    });
    const json = await response.json();
    if (!json.success) throw new Error(json.message);
    window.piaUsuario = null;
    window.location.hash = "home";
    actualizarInterfazSesion();
    resolverRuta();
  } catch (error) {
    console.error("Error al cerrar sesion:", error);
    alert("No fue posible cerrar la sesion.");
  }
}

/* -------------------------------------------------------------------- acceso */

function mostrarAcceso() {
  gate.classList.remove("hidden");
  campo.activar();
  $("login-error").textContent = "";
  verVista("login");
  $("in-clave").focus();
}

function sacudirCaja() {
  const caja = document.querySelector("#login-modal .admin-box");
  caja.classList.remove("sacudir");
  void caja.offsetWidth; // reinicia la animación
  caja.classList.add("sacudir");
}

function verVista(nombre) {
  $("form-login").hidden = nombre !== "login";
  $("form-recuperar").hidden = nombre !== "recuperar";
  $("vista-codigo").hidden = nombre !== "codigo";
  const foco = { login: "in-clave", recuperar: "in-codigo" }[nombre];
  if (foco) $(foco).focus();
}

async function pedirAuth(cuerpo) {
  const response = await fetch("api/auth.php", {
    method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(cuerpo),
  });
  return response.json();
}

async function entrar(usuario) {
  window.piaUsuario = usuario;
  campo.pulso();
  await new Promise((r) => setTimeout(r, 450));
  actualizarInterfazSesion();
  resolverRuta();
}

async function enviarAcceso(evento) {
  evento.preventDefault();
  const error = $("login-error");
  const boton = $("btn-entrar");
  const clave = $("in-clave").value;
  error.textContent = "";
  if (!clave) {
    error.textContent = "Escribe la clave maestra.";
    return;
  }

  boton.disabled = true;
  boton.textContent = "Verificando…";
  try {
    const json = await pedirAuth({ accion: "login", clave });
    if (!json.success || !json.data) {
      error.textContent = json.message || "No fue posible iniciar sesión.";
      $("in-clave").value = "";
      $("in-clave").focus();
      sacudirCaja();
      return;
    }
    $("in-clave").value = "";
    await entrar(json.data);
  } catch (errorRed) {
    console.error("Error al iniciar sesion:", errorRed);
    error.textContent = "No fue posible conectar con el servidor.";
  } finally {
    boton.disabled = false;
    boton.textContent = "Acceder";
  }
}

let usuarioPendiente = null;

async function enviarRecuperacion(evento) {
  evento.preventDefault();
  const error = $("recuperar-error");
  const boton = $("btn-restablecer");
  const codigo = $("in-codigo").value.trim();
  const claveNueva = $("in-clave-nueva").value;
  error.textContent = "";
  if (!codigo || !claveNueva) {
    error.textContent = "Completa el código y la clave nueva.";
    return;
  }
  if (claveNueva.length < 10) {
    error.textContent = "La clave nueva debe tener al menos 10 caracteres.";
    return;
  }

  boton.disabled = true;
  boton.textContent = "Verificando…";
  try {
    const json = await pedirAuth({ accion: "recuperar", codigo, clave_nueva: claveNueva });
    if (!json.success || !json.data) {
      error.textContent = json.message || "No fue posible restablecer la clave.";
      sacudirCaja();
      return;
    }
    $("in-codigo").value = "";
    $("in-clave-nueva").value = "";
    usuarioPendiente = json.data.usuario;
    $("codigo-nuevo").textContent = json.data.codigo_recuperacion;
    verVista("codigo");
  } catch (errorRed) {
    console.error("Error al recuperar la clave:", errorRed);
    error.textContent = "No fue posible conectar con el servidor.";
  } finally {
    boton.disabled = false;
    boton.textContent = "Restablecer clave";
  }
}

async function copiarCodigo() {
  const boton = $("btn-copiar-codigo");
  try {
    await navigator.clipboard.writeText($("codigo-nuevo").textContent);
    boton.textContent = "Copiado ✓";
  } catch {
    boton.textContent = "Selecciónalo y cópialo a mano";
  }
}

// Los navegadores ignoran autocomplete="off" en campos de contraseña y muestran sugerencias.
// Si el navegador puede ocultar el texto con CSS, el campo es de texto normal (sin sugerencias).
const ocultaConCss = window.CSS?.supports?.("-webkit-text-security", "disc") ?? false;

// Solo lectura hasta que el usuario toca el campo: el navegador y los gestores de claves no lo rellenan.
function bloquearRelleno(idCampo) {
  const el = $(idCampo);
  el.readOnly = true;
  const activar = () => { el.readOnly = false; };
  el.addEventListener("keydown", activar, { once: true });
  el.addEventListener("pointerdown", activar, { once: true });
}

function nombreAlAzar() {
  return `f${Math.random().toString(36).slice(2, 10)}`;
}

function prepararCampoSecreto(idCampo) {
  const campoClave = $(idCampo);
  campoClave.name = nombreAlAzar();
  campoClave.setAttribute("autocorrect", "off");
  campoClave.setAttribute("autocapitalize", "none");
  campoClave.setAttribute("spellcheck", "false");
  if (ocultaConCss) {
    campoClave.type = "text";
    campoClave.classList.add("secreto");
  }
}

function alternarClave(idCampo, idBoton) {
  const campoClave = $(idCampo);
  const visible = ocultaConCss ? campoClave.classList.contains("secreto") : campoClave.type === "password";
  if (ocultaConCss) campoClave.classList.toggle("secreto", !visible);
  else campoClave.type = visible ? "text" : "password";
  $(idBoton).setAttribute("aria-pressed", String(visible));
  $(idBoton).setAttribute("aria-label", visible ? "Ocultar clave" : "Mostrar clave");
}

/* ------------------------------------------------------------ estado del VPS */

function pintarEstado(e) {
  for (const app of e.apps) {
    const tarjeta = document.querySelector(`.card[data-app="${app.id}"]`);
    if (!tarjeta) continue;
    tarjeta.dataset.estado = app.en_linea ? "ok" : "caida";
    tarjeta.querySelector(".app-status-texto").textContent = app.en_linea ? `en línea · ${app.ms} ms` : "sin respuesta";
  }
}

async function cargarEstado() {
  if (!window.piaUsuario || document.hidden) return;
  try {
    const response = await fetch("api/estado.php");
    if (response.status === 401) { window.piaUsuario = null; resolverRuta(); return; }
    const json = await response.json();
    if (json.success) pintarEstado(json.data);
  } catch (error) {
    console.error("No se pudo leer el estado del sistema:", error);
  }
}

function iniciarEstado() {
  detenerEstado();
  cargarEstado();
  temporizadorEstado = window.setInterval(cargarEstado, 15000);
}

function detenerEstado() {
  window.clearInterval(temporizadorEstado);
}

/* ----------------------------------------------------------------- paleta Ctrl+K */

const sinAcentos = (texto) => texto.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
const abrirApp = (host) => () => window.open(`https://${host}`, "_blank", "noopener");

const comandos = [
  { nombre: "Ir al inicio", clave: "inicio home bienvenida", accion: () => navegar("home") },
  { nombre: "Abrir Tips", clave: "tips notas", accion: () => navegar("tips") },
  { nombre: "Abrir Drive", clave: "drive archivos", accion: () => navegar("drive") },
  { nombre: "Ver Clases", clave: "clases materias", accion: () => navegar("clases") },
  { nombre: "Ver Mis apps", clave: "apps aplicaciones proyectos", accion: () => navegar("apps") },
  { nombre: "Abrir Clase: Bases de datos", clave: "clases sql bases datos", accion: abrirApp("basesdatos-compucec.netlify.app") },
  { nombre: "Abrir RAEE", clave: "raee", accion: abrirApp("raee.smarteksoft.com") },
  { nombre: "Abrir Soporte", clave: "soporte", accion: abrirApp("soporte.smarteksoft.com") },
  { nombre: "Abrir Tu Cultura", clave: "tucultura cultura tienda", accion: abrirApp("tucultura.smarteksoft.com") },
  { nombre: "Cerrar sesión", clave: "salir logout cerrar sesion", accion: cerrarSesion },
];
let coincidencias = comandos;
let seleccion = 0;

function pintarPaleta() {
  const lista = $("paleta-lista");
  lista.textContent = "";
  coincidencias.forEach((c, i) => {
    const li = document.createElement("li");
    li.setAttribute("role", "option");
    li.setAttribute("aria-selected", String(i === seleccion));
    li.textContent = c.nombre;
    li.addEventListener("click", () => ejecutarComando(i));
    li.addEventListener("pointermove", () => { if (seleccion !== i) { seleccion = i; pintarPaleta(); } });
    lista.appendChild(li);
  });
  if (!coincidencias.length) {
    const vacio = document.createElement("li");
    vacio.className = "palette-vacio";
    vacio.textContent = "Sin resultados";
    lista.appendChild(vacio);
  }
}

function filtrarPaleta() {
  const q = sinAcentos($("paleta-input").value.trim());
  coincidencias = q ? comandos.filter((c) => sinAcentos(`${c.nombre} ${c.clave}`).includes(q)) : comandos;
  seleccion = 0;
  pintarPaleta();
}

function abrirPaleta() {
  if (!window.piaUsuario) return;
  $("paleta").classList.remove("hidden");
  $("paleta-input").value = "";
  filtrarPaleta();
  $("paleta-input").focus();
}

function cerrarPaleta() {
  $("paleta").classList.add("hidden");
}

function ejecutarComando(indice) {
  const comando = coincidencias[indice];
  if (!comando) return;
  cerrarPaleta();
  comando.accion();
}

function configurarPaleta() {
  $("paleta-input").addEventListener("input", filtrarPaleta);
  $("paleta").addEventListener("pointerdown", (e) => { if (e.target === $("paleta")) cerrarPaleta(); });
  $("paleta-input").addEventListener("keydown", (e) => {
    if (e.key === "ArrowDown") { e.preventDefault(); seleccion = Math.min(seleccion + 1, coincidencias.length - 1); pintarPaleta(); }
    else if (e.key === "ArrowUp") { e.preventDefault(); seleccion = Math.max(seleccion - 1, 0); pintarPaleta(); }
    else if (e.key === "Enter") { e.preventDefault(); ejecutarComando(seleccion); }
  });
  document.addEventListener("keydown", (e) => {
    const abierta = !$("paleta").classList.contains("hidden");
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
      e.preventDefault();
      if (abierta) cerrarPaleta();
      else abrirPaleta();
    } else if (e.key === "Escape" && abierta) {
      cerrarPaleta();
    }
  });
}

/* --------------------------------------------------------------------- inicio */

function configurarNavegacion() {
  $("btn-modulo-tips").addEventListener("click", () => navegar("tips"));
  $("btn-modulo-drive").addEventListener("click", () => navegar("drive"));
  $("btn-modulo-clases").addEventListener("click", () => navegar("clases"));
  $("btn-modulo-apps").addEventListener("click", () => navegar("apps"));
  $("btn-volver-home-tips").addEventListener("click", () => navegar("home"));
  $("btn-volver-home-drive").addEventListener("click", () => navegar("home"));
  $("btn-volver-home-apps").addEventListener("click", () => navegar("home"));
  $("btn-volver-home-clases").addEventListener("click", () => navegar("home"));
  $("btn-cerrar-sesion-home").addEventListener("click", cerrarSesion);
  $("in-codigo").name = nombreAlAzar();
  bloquearRelleno("in-codigo");
  bloquearRelleno("in-clave");
  bloquearRelleno("in-clave-nueva");
  prepararCampoSecreto("in-clave");
  prepararCampoSecreto("in-clave-nueva");
  $("btn-ver-clave").addEventListener("click", () => alternarClave("in-clave", "btn-ver-clave"));
  $("btn-ver-clave-nueva").addEventListener("click", () => alternarClave("in-clave-nueva", "btn-ver-clave-nueva"));
  $("form-login").addEventListener("submit", enviarAcceso);
  $("form-recuperar").addEventListener("submit", enviarRecuperacion);
  $("btn-olvide").addEventListener("click", () => verVista("recuperar"));
  $("btn-volver-login").addEventListener("click", () => verVista("login"));
  $("btn-copiar-codigo").addEventListener("click", copiarCodigo);
  $("btn-continuar").addEventListener("click", () => {
    $("codigo-nuevo").textContent = "";
    $("btn-copiar-codigo").textContent = "Copiar código";
    entrar(usuarioPendiente);
  });
  window.addEventListener("hashchange", resolverRuta);
  if (!window.location.hash) window.location.hash = "home";
}

document.addEventListener("visibilitychange", () => {
  const enInicio = !routes.home.classList.contains("screen-hidden") || !routes.apps.classList.contains("screen-hidden");
  if (!document.hidden && window.piaUsuario && enInicio) cargarEstado();
});

document.addEventListener("DOMContentLoaded", () => {
  configurarNavegacion();
  configurarPaleta();
  consultarSesion();
});
