import Alpine from 'alpinejs';

const TOKEN_KEY = 'skripsi_status_token';

function storage(action, value) {
    try {
        if (action === 'get') return localStorage.getItem(TOKEN_KEY);
        if (action === 'set') localStorage.setItem(TOKEN_KEY, value);
        if (action === 'del') localStorage.removeItem(TOKEN_KEY);
    } catch (e) {
        return null; // private mode / blocked storage: status link in WhatsApp still works
    }
    return null;
}

async function looksLikePdf(file) {
    const head = await file.slice(0, 1024).arrayBuffer();
    const text = new TextDecoder('latin1').decode(head);
    return text.includes('%PDF-');
}

Alpine.data('uploader', (schema) => ({
    schema,
    values: {},
    errors: {},
    file: null,
    fileError: null,
    nimState: null,
    stage: 'form', // form | uploading | status
    progress: 0,
    formError: null,
    token: null,
    status: null,
    pollTimer: null,
    dragging: false,

    init() {
        for (const f of schema.fields) {
            this.values[f.key] = f.type === 'select' && f.options.length === 1 ? f.options[0] : '';
        }
        const params = new URLSearchParams(window.location.search);
        const token = params.get('status') || storage('get');
        if (token && /^[A-Za-z0-9]{40}$/.test(token)) {
            this.token = token;
            this.stage = 'status';
            this.loadStatus();
        }
    },

    get programs() {
        const faculty = this.schema.faculties.find((f) => String(f.id) === String(this.values.fakultas));
        return faculty ? faculty.programs : [];
    },

    fieldId(key) {
        return 'f_' + key;
    },

    validateField(field) {
        const value = String(this.values[field.key] ?? '').trim();
        let error = null;
        if (field.required && value === '') {
            error = `${field.label} wajib diisi.`;
        } else if (value !== '') {
            if (field.max_length && value.length > field.max_length) {
                error = `${field.label} maksimal ${field.max_length} karakter.`;
            } else if (field.pattern && !new RegExp(field.pattern, 'u').test(value)) {
                error = `Format ${field.label} tidak valid.`;
            } else if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                error = 'Format email tidak valid.';
            }
        }
        this.errors[field.key] = error;
        return !error;
    },

    async checkNim() {
        const field = this.schema.fields.find((f) => f.key === 'nim');
        this.nimState = null;
        if (!field || !this.validateField(field)) return;
        try {
            const res = await fetch(`/api/nim/${encodeURIComponent(this.values.nim.trim())}`, { headers: { Accept: 'application/json' } });
            if (res.ok) this.nimState = await res.json();
        } catch (e) {
            this.nimState = null; // the server re-checks on submit
        }
    },

    async pickFile(file) {
        this.file = null;
        this.fileError = null;
        if (!file) return;
        const maxBytes = this.schema.max_mb * 1024 * 1024;
        if (!file.name.toLowerCase().endsWith('.pdf') || (file.type && file.type !== 'application/pdf')) {
            this.fileError = 'File harus berformat PDF.';
        } else if (file.size > maxBytes) {
            this.fileError = `Ukuran file ${(file.size / 1048576).toFixed(1)} MB melebihi batas ${this.schema.max_mb} MB.`;
        } else if (file.size === 0) {
            this.fileError = 'File kosong.';
        } else if (!(await looksLikePdf(file))) {
            this.fileError = 'Isi file bukan PDF yang valid.';
        }
        if (!this.fileError) this.file = file;
    },

    onDrop(event) {
        this.dragging = false;
        this.pickFile(event.dataTransfer.files[0]);
    },

    formatSize(bytes) {
        return (bytes / 1048576).toFixed(1) + ' MB';
    },

    async submit() {
        this.formError = null;
        const valid = this.schema.fields.map((f) => this.validateField(f)).every(Boolean);
        if (!this.file) this.fileError = this.fileError || 'Pilih file PDF skripsi.';
        if (!valid || !this.file) {
            this.formError = 'Periksa kembali isian yang ditandai.';
            return;
        }
        await this.checkNim();
        if (this.nimState && !this.nimState.allowed) {
            this.formError = this.nimState.message;
            return;
        }

        const data = new FormData();
        for (const f of this.schema.fields) data.append(f.key, String(this.values[f.key] ?? '').trim());
        data.append('file', this.file);

        this.stage = 'uploading';
        this.progress = 0;
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/submissions');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) this.progress = Math.round((e.loaded / e.total) * 100);
        };
        xhr.onload = () => {
            let body = {};
            try { body = JSON.parse(xhr.responseText); } catch (e) { /* non-JSON error page */ }
            if (xhr.status >= 200 && xhr.status < 300 && body.token) {
                this.token = body.token;
                storage('set', body.token);
                this.status = body;
                this.stage = 'status';
                history.replaceState(null, '', '?status=' + body.token);
                this.poll();
                return;
            }
            this.stage = 'form';
            if (xhr.status === 422 && body.errors) {
                for (const [key, messages] of Object.entries(body.errors)) {
                    if (key === 'file') this.fileError = messages[0];
                    else this.errors[key] = messages[0];
                }
            }
            this.formError = body.message || (xhr.status === 413 ? 'File terlalu besar.' : xhr.status === 429
                ? 'Terlalu banyak percobaan. Coba lagi nanti.' : 'Unggah gagal. Periksa koneksi lalu coba lagi.');
        };
        xhr.onerror = () => {
            this.stage = 'form';
            this.formError = 'Koneksi terputus saat mengunggah. Coba lagi.';
        };
        xhr.send(data);
    },

    async loadStatus() {
        try {
            const res = await fetch(`/api/status/${this.token}`, { headers: { Accept: 'application/json' } });
            if (res.status === 404) {
                storage('del');
                this.reset();
                return;
            }
            if (res.ok) this.status = await res.json();
        } catch (e) {
            /* keep last known status, retry on next poll */
        }
        this.poll();
    },

    poll() {
        clearTimeout(this.pollTimer);
        if (this.status && this.status.state === 'checking') {
            this.pollTimer = setTimeout(() => this.loadStatus(), 3000);
        }
    },

    reupload() {
        // Keep the previous answers; only the file must be chosen again.
        if (this.status) {
            this.values.nim = this.status.nim;
            this.values.nama = this.status.nama;
            this.values.judul = this.status.judul;
        }
        this.reset();
    },

    reset() {
        clearTimeout(this.pollTimer);
        storage('del');
        history.replaceState(null, '', window.location.pathname);
        this.token = null;
        this.status = null;
        this.file = null;
        this.nimState = null;
        this.stage = 'form';
    },
}));

Alpine.start();
