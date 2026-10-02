const DURACION_SESION_MS = 2 * 60 * 60 * 1000;

function cerrarSesionLocal() {
    localStorage.removeItem('usuarioSesion');
    localStorage.removeItem('sesionIniciadaEn');
    window.location.replace('login.html');
}

function obtenerSesionActiva(rolRequerido) {
    let usuario = null;
    try {
        usuario = JSON.parse(localStorage.getItem('usuarioSesion') || 'null');
    } catch (error) {
        cerrarSesionLocal();
        return null;
    }

    const inicioSesion = Number(localStorage.getItem('sesionIniciadaEn'));
    const tiempoTranscurrido = Date.now() - inicioSesion;
    if (!usuario || !Number.isFinite(inicioSesion) || tiempoTranscurrido < 0 || tiempoTranscurrido >= DURACION_SESION_MS) {
        cerrarSesionLocal();
        return null;
    }

    if (rolRequerido && usuario.rol !== rolRequerido) {
        window.location.replace('login.html');
        return null;
    }

    window.setTimeout(cerrarSesionLocal, DURACION_SESION_MS - tiempoTranscurrido);
    return usuario;
}