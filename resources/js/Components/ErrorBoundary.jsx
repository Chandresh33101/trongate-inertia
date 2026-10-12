// resources/js/Components/ErrorBoundary.jsx
import React from 'react';

class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error) {
    // Update state so the next render shows the fallback UI.
    return { hasError: true, error };
  }

  componentDidCatch(error, errorInfo) {
    // Optional: Log the error to an analytics or error tracking service
    console.error("Caught by ErrorBoundary:", error, errorInfo);
  }

  render() {
    if (this.state.hasError) {
      // Custom fallback UI using <code> tags
      return (
        <div className="container" style={{ padding: '20px', backgroundColor: '#fff', color: '#111', fontFamily: 'sans-serif' }}>
          <h2 style={{ color: '#dc2626' }}>Application Render Error</h2>
          <p>The application encountered a client-side JavaScript error:</p>
          <pre style={{ 
            backgroundColor: '#f4f4f5', 
            padding: '15px', 
            borderRadius: '5px', 
            overflowX: 'auto',
            border: '1px solid #e4e4e7'
          }}>
            <code>{this.state.error?.stack || this.state.error?.toString()}</code>
          </pre>
          <button 
            onClick={() => window.location.reload()} 
            style={{ marginTop: '15px', padding: '8px 16px', cursor: 'pointer' }}
          >
            Reload Page
          </button>
        </div>
      );
    }

    return this.props.children; 
  }
}

export default ErrorBoundary;
