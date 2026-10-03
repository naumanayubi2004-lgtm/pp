import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';

// Blank-screen delay before the app renders. Set to 0 to disable.
const BLANK_SCREEN_DELAY_MS = 5000;

import Layout from './Layout';
import Home from './pages/Home';
import SelectIdentity from './pages/SelectIdentity';
import DocumentEntry from './pages/DocumentEntry';
import DateOfBirth from './pages/DateOfBirth';
import RecordNotFound from './pages/RecordNotFound';
import SecurityCode from './pages/SecurityCode';
import Otp from './pages/Otp';
import Evisa from './pages/Evisa';

function App() {
    return (
        <BrowserRouter>
            <Layout>
                <Routes>
                    <Route path="/" element={<Home />} />

                    <Route path="/id-question" element={<SelectIdentity />} />
                    <Route path="/id-question/passport" element={<DocumentEntry documentType="passport" />} />
                    <Route path="/id-question/national-id" element={<DocumentEntry documentType="national_id" />} />
                    <Route path="/id-question/biometric" element={<DocumentEntry documentType="biometric" />} />
                    <Route path="/id-question/customer-number" element={<DocumentEntry documentType="customer_number" />} />
                    <Route path="/id-question/date-of-birth" element={<DateOfBirth />} />
                    <Route path="/id-question/record-not-found" element={<RecordNotFound />} />
                    <Route path="/id-question/security-code" element={<SecurityCode />} />
                    <Route path="/id-question/security-code/verify" element={<Otp />} />

                    <Route path="/status" element={<Evisa />} />

                    {/* Unknown paths fall back to the guide home page */}
                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </Layout>
        </BrowserRouter>
    );
}

function BlankScreenGate({ children }) {
    const [ready, setReady] = useState(BLANK_SCREEN_DELAY_MS <= 0);

    useEffect(() => {
        if (ready) return;
        const timer = setTimeout(() => setReady(true), BLANK_SCREEN_DELAY_MS);
        return () => clearTimeout(timer);
    }, [ready]);

    // Blank white screen until the delay elapses.
    if (!ready) return null;

    return children;
}

createRoot(document.getElementById('app')).render(
    <React.StrictMode>
        <BlankScreenGate>
            <App />
        </BlankScreenGate>
    </React.StrictMode>
);
