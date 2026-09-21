/**
 * gulpfile.js — SIGA-COCIAP
 * Compilador de assets frontend.
 * Uso:
 *   gulp        → compilar y vigilar cambios (desarrollo)
 *   gulp build  → compilar una sola vez (producción)
 */

const gulp        = require('gulp');
const sass        = require('gulp-sass')(require('sass'));
const plumber     = require('gulp-plumber');
const autoprefixer= require('gulp-autoprefixer');
const cleanCSS    = require('gulp-clean-css');
const uglify      = require('gulp-uglify');
const browserSync = require('browser-sync').create();

// ── Rutas del proyecto ───────────────────────────────────────
const paths = {
    sass: {
        src:  'resources/sass/**/*.scss',
        dest: 'public/css/'
    },
    js: {
        src:  'resources/js/**/*.js',
        dest: 'public/js/'
    },
    php: {
        watch: '**/*.php'   // para recargar el navegador al guardar PHP
    }
};

// ── Configuración de BrowserSync ────────────────────────────
// Proxy apunta a la raíz de Apache (sin prefijo de ruta).
// BrowserSync reenvía el Host original del navegador (localhost:3000)
// a Apache, permitiendo que PHP detecte el puerto dinámicamente.
const proxyURL = 'localhost';

// ── Tarea: compilar SASS ─────────────────────────────────────
function compilarSass() {
    return gulp
        // app.scss = la app; errores.scss = páginas de error sueltas
        // (shared/403|404|500), que no cargan app.css. Salen app.css y errores.css.
        .src(['resources/sass/app.scss', 'resources/sass/errores.scss'])
        .pipe(plumber({
            errorHandler: function(err) {
                console.error('❌ Error SASS:', err.message);
                this.emit('end');
            }
        }))
        .pipe(sass({ outputStyle: 'expanded' }))
        .pipe(autoprefixer({ overrideBrowserslist: ['last 2 versions'] }))
        .pipe(cleanCSS({ level: 1 }))
        .pipe(gulp.dest(paths.sass.dest))
        .pipe(browserSync.stream());      // inyecta CSS sin recargar la página
}

// ── Tarea: compilar JS ───────────────────────────────────────
function compilarJs() {
    return gulp
        .src('resources/js/**/*.js')
        .pipe(plumber())
        .pipe(uglify())
        .pipe(gulp.dest(paths.js.dest))
        .pipe(browserSync.stream());
}

// ── Tarea: iniciar BrowserSync ───────────────────────────────
function servidor(done) {
    browserSync.init({
        proxy:     proxyURL,
        startPath: '/siga_cociap/public',   // abre directo en la app
        notify:    false,
        open:      true,
        port:      3000
    });
    done();
}

// ── Tarea: recargar navegador al cambiar PHP ─────────────────
function recargarPhp(done) {
    browserSync.reload();
    done();
}

// ── Tarea: vigilar cambios ───────────────────────────────────
function vigilar() {
    gulp.watch(paths.sass.src, compilarSass);
    gulp.watch(paths.js.src,   compilarJs);
    gulp.watch(paths.php.watch, recargarPhp);
}

// ── Exportar tareas ──────────────────────────────────────────

// gulp build → compilar una sola vez
exports.build = gulp.series(compilarSass, compilarJs);

// gulp → desarrollo completo con live reload
exports.default = gulp.series(
    compilarSass,
    compilarJs,
    servidor,
    vigilar
);
