import { createRoot } from 'react-dom/client';
import App from './App';
import { AuthModalProvider } from './components/AuthModal';
import { AuthProvider } from './lib/auth';
import { Router } from './router';

const el = document.getElementById('portal');
if (el) {
    createRoot(el).render(
        <AuthProvider>
            <AuthModalProvider>
                <Router>
                    <App />
                </Router>
            </AuthModalProvider>
        </AuthProvider>,
    );
}
