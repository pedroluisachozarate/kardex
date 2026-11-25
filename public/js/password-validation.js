(() => {
    const form = document.querySelector('[data-password-validation]');
    if (!form) return;
    const fields = Object.fromEntries(['current_password', 'password', 'password_confirmation'].map(name => [name, form.elements.namedItem(name)]));
    const touched = new Set();
    let timer;
    const show = (name, message = '', valid = false) => {
        const feedback = form.querySelector(`[data-feedback="${name}"]`);
        feedback.textContent = message;
        feedback.hidden = !message;
        feedback.classList.toggle('is-valid', Boolean(message && valid));
        feedback.classList.toggle('is-invalid', Boolean(message && !valid));
        fields[name].setAttribute('aria-invalid', String(Boolean(message && !valid)));
    };
    const validate = (force = false) => {
        let ok = true;
        const current = fields.current_password.value;
        const password = fields.password.value;
        const confirmation = fields.password_confirmation.value;
        if (force || touched.has('current_password')) {
            show('current_password', current ? '' : 'Escribe tu contraseña actual.');
            if (!current) ok = false;
        }
        if (force || touched.has('password')) {
            let error = '';
            if (!password) error = 'Escribe la nueva contraseña.';
            else if ([...password].length < 10) error = 'Usa al menos 10 caracteres.';
            else if (!/\p{L}/u.test(password)) error = 'Incluye al menos una letra.';
            else if (!/\p{N}/u.test(password)) error = 'Incluye al menos un número.';
            else if (current && password === current) error = 'Debe ser diferente de la contraseña actual.';
            show('password', error || 'Cumple los requisitos.', !error);
            if (error) ok = false;
        }
        if (force || touched.has('password_confirmation')) {
            let error = '';
            if (!confirmation) error = 'Confirma la nueva contraseña.';
            else if (!password) error = 'Escribe primero la nueva contraseña.';
            else if (confirmation !== password) error = 'Las contraseñas no coinciden.';
            show('password_confirmation', error || 'Las contraseñas coinciden.', !error);
            if (error) ok = false;
        }
        return ok;
    };
    Object.entries(fields).forEach(([name, input]) => {
        input.addEventListener('input', () => {
            touched.add(name);
            clearTimeout(timer);
            show(name);
            if (name === 'password' || name === 'current_password') {
                show('password');
                show('password_confirmation');
            }
            timer = setTimeout(() => validate(), 500);
        });
    });
    form.addEventListener('submit', event => {
        clearTimeout(timer);
        if (!validate(true)) {
            event.preventDefault();
            form.querySelector('input[aria-invalid="true"]')?.focus();
        }
    });
})();
