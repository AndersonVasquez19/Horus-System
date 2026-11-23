import React from 'react';

interface RobotMascotProps {
  portrait?: boolean;
  className?: string;
}

export function RobotMascot({ portrait = true, className = '' }: RobotMascotProps) {
  return (
    <svg
      className={`hc-robot ${portrait ? 'hc-robot-portrait' : ''} ${className}`}
      viewBox="12 0 96 86"
      fill="none"
      aria-hidden="true"
      focusable="false"
    >
      <defs>
        <linearGradient id="hc-robot-shell" x1="29" y1="27" x2="90" y2="106" gradientUnits="userSpaceOnUse">
          <stop stopColor="#f8f6ee" />
          <stop offset=".55" stopColor="#eaf3ff" />
          <stop offset="1" stopColor="#a8c5ec" />
        </linearGradient>
        <linearGradient id="hc-robot-visor" x1="38" y1="34" x2="79" y2="75" gradientUnits="userSpaceOnUse">
          <stop stopColor="#2563eb" />
          <stop offset="1" stopColor="#142e52" />
        </linearGradient>
        <linearGradient id="hc-robot-gold" x1="42" y1="88" x2="79" y2="118" gradientUnits="userSpaceOnUse">
          <stop stopColor="#f7d889" />
          <stop offset="1" stopColor="#eab84a" />
        </linearGradient>
      </defs>
      <path
        d="M60 24V13"
        stroke="#2563eb"
        strokeWidth="4"
        strokeLinecap="round"
      />
      <circle
        cx="60"
        cy="10"
        r="6"
        fill="#eab84a"
        stroke="#f8f6ee"
        strokeWidth="2"
      />
      <rect
        x="16"
        y="42"
        width="13"
        height="24"
        rx="6"
        fill="#2563eb"
        stroke="#a8c5ec"
        strokeWidth="1.5"
      />
      <rect
        x="91"
        y="42"
        width="13"
        height="24"
        rx="6"
        fill="#2563eb"
        stroke="#a8c5ec"
        strokeWidth="1.5"
      />
      <rect
        x="24"
        y="23"
        width="72"
        height="62"
        rx="25"
        fill="url(#hc-robot-shell)"
        stroke="#7295c4"
        strokeWidth="1.5"
      />
      <path
        d="M36 33c7-7 17-7 25-7"
        stroke="#fff"
        strokeWidth="3"
        strokeLinecap="round"
        opacity=".9"
      />
      <rect
        x="31"
        y="34"
        width="58"
        height="40"
        rx="17"
        fill="url(#hc-robot-visor)"
        stroke="#142e52"
        strokeWidth="1.5"
      />
      <path
        d="M39 40c10-5 27-4 39 0"
        stroke="#06b6d4"
        strokeWidth="2"
        strokeLinecap="round"
        opacity=".5"
      />
      <g className="hc-robot-eyes" fill="#67e8f9">
        <rect x="43" y="46" width="8" height="12" rx="4" />
        <rect x="69" y="46" width="8" height="12" rx="4" />
      </g>
      <path
        d="M54 63c4 4 8 4 12 0"
        stroke="#67e8f9"
        strokeWidth="2.5"
        strokeLinecap="round"
      />
      <ellipse cx="39" cy="61" rx="4" ry="2" fill="#eab84a" opacity=".55" />
      <ellipse cx="81" cy="61" rx="4" ry="2" fill="#eab84a" opacity=".55" />
      <path
        d="M53 79h14"
        stroke="#7295c4"
        strokeWidth="2"
        strokeLinecap="round"
      />
    </svg>
  );
}
