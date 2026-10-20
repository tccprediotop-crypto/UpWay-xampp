from flask import Flask, request, session, redirect, url_for, flash, render_template, g
import pymysql
from pymysql.cursors import DictCursor
import os
from core import formatar_status_pacote, classe_status_pacote

app = Flask(__name__, template_folder='templates')
app.secret_key = os.environ.get('SECRET_KEY', 'upway-dev-secret-change-me')

DB_CONFIG = {
    'host': '127.0.0.1',
    'user': 'root',
    'password': 'Vivar@01',
    'db': 'upway_db',
    'charset': 'utf8mb4',
    'cursorclass': DictCursor,
    'autocommit': False
}

def get_db():
    if 'db' not in g:
        g.db = pymysql.connect(**DB_CONFIG)
    return g.db

@app.teardown_appcontext
def close_db(e):
    db = g.pop('db', None)
    if db:
        db.close()

def require_role(allowed):
    def decorator(f):
        def wrapper(*args, **kwargs):
            role = session.get('usuario_role')
            if role not in allowed:
                return "Acesso negado.", 403
            if 'usuario_id' not in session:
                return redirect(url_for('login_redirect'))
            return f(*args, **kwargs)
        wrapper.__name__ = f.__name__
        return wrapper
    return decorator

@app.route('/login-redirect')
def login_redirect():
    return redirect('http://localhost/UpWay(xampp)/login.php')

@app.route('/porteiro/dashboard')
@require_role(['PORTER','ADMIN'])
def porteiro_dashboard():
    db = get_db()
    with db.cursor() as cur:
        cur.execute("SELECT COUNT(*) as c FROM packages WHERE status IN ('NOTIFIED','TOKEN_GENERATED')")
        pendentes = cur.fetchone()['c']
        cur.execute("SELECT COUNT(*) as c FROM users WHERE role='RESIDENT' AND is_active=1")
        moradores = cur.fetchone()['c']
        cur.execute("SELECT COUNT(*) as c FROM packages WHERE DATE(received_at)=CURDATE()")
        recebidas_hoje = cur.fetchone()['c']
        cur.execute("SELECT COUNT(*) as c FROM packages WHERE status='TOKEN_GENERATED'")
        tokens_ativos = cur.fetchone()['c']
        cur.execute("SELECT COUNT(*) as c FROM packages")
        total_encomendas = cur.fetchone()['c']

        metricas = {
            'pendentes_retirada': pendentes,
            'moradores': moradores,
            'recebidas_hoje': recebidas_hoje,
            'tokens_ativos': tokens_ativos,
            'total_encomendas': total_encomendas
        }

        cur.execute("SELECT status, COUNT(*) AS total FROM packages GROUP BY status")
        status_brutos = {row['status']: row['total'] for row in cur.fetchall()}
        for s in ['RECEIVED','NOTIFIED','TOKEN_GENERATED','PICKED_UP']:
            status_brutos.setdefault(s,0)

        cur.execute("""
            SELECT p.id, p.carrier, p.tracking_code, p.status, p.received_at,
                   u.full_name AS resident_name, t.name AS tower_name, un.unit_number
            FROM packages p
            INNER JOIN users u ON u.id = p.resident_id
            LEFT JOIN units un ON un.id = u.unit_id
            LEFT JOIN towers t ON t.id = un.tower_id
            ORDER BY p.received_at DESC LIMIT 8
        """)
        ultimas_encomendas = cur.fetchall()

    proporcao_tokens = 0
    if metricas['total_encomendas']>0:
        proporcao_tokens = int(round(metricas['tokens_ativos']/metricas['total_encomendas']*360))

    return render_template('porteiro_dashboard.html',
        metricas=metricas,
        status_brutos=status_brutos,
        ultimas_encomendas=ultimas_encomendas,
        proporcao_tokens=proporcao_tokens,
        formatar_status_pacote=formatar_status_pacote,
        classe_status_pacote=classe_status_pacote
    )

if __name__ == '__main__':
    app.run(host='127.0.0.1', port=5000, debug=True)
